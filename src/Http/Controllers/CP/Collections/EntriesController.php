<?php

namespace Statamic\Http\Controllers\CP\Collections;

use Statamic\Facades\Site;
use Statamic\Facades\Asset;
use Statamic\Facades\Entry;
use Statamic\CP\Column;
use Statamic\CP\Breadcrumbs;
use Statamic\Facades\Action;
use Statamic\Facades\Blueprint;
use Illuminate\Http\Request;
use Statamic\Facades\Collection;
use Statamic\Facades\Preference;
use Statamic\Facades\User;
use Illuminate\Http\Resources\Json\Resource;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Events\Data\PublishBlueprintFound;
use Statamic\Http\Requests\FilteredSiteRequest;
use Statamic\Contracts\Entries\Entry as EntryContract;

class EntriesController extends CpController
{
    public function index(FilteredSiteRequest $request, $collection)
    {
        $this->authorize('view', $collection);

        $query = $this->indexQuery($collection);

        $this->filter($query, $request->filters);

        $sortField = request('sort');
        $sortDirection = request('order', 'asc');

        if (!$sortField && !request('search')) {
            $sortField = $collection->sortField();
            $sortDirection = $collection->sortDirection();
        }

        if ($sortField) {
            $query->orderBy($sortField, $sortDirection);
        }

        $entries = $query
            ->paginate(request('perPage'))
            ->supplement(function ($entry) {
                return [
                    'viewable' => User::current()->can('view', $entry),
                    'editable' => User::current()->can('edit', $entry),
                    'actions' => Action::for('entries', [], $entry),
                ];
            })
            ->preProcessForIndex();

        if ($collection->dated()) {
            $entries->supplement('date', function ($entry) {
                return $entry->date()->inPreferredFormat();
            });
        }

        $columns = $collection->entryBlueprint()
            ->columns()
            ->setPreferred("collections.{$collection->handle()}.columns")
            ->rejectUnlisted()
            ->values();

        return Resource::collection($entries)->additional(['meta' => [
            'filters' => $request->filters,
            'sortColumn' => $sortField,
            'columns' => $columns,
        ]]);
    }

    protected function filter($query, $filters)
    {
        foreach ($filters as $handle => $values) {
            $class = app('statamic.scopes')->get($handle);
            $filter = app($class);
            $filter->apply($query, $values);
        }
    }

    protected function indexQuery($collection)
    {
        $query = $collection->queryEntries();

        if ($search = request('search')) {
            if ($collection->hasSearchIndex()) {
                return $collection->searchIndex()->ensureExists()->search($search);
            }

            $query->where('title', 'like', '%'.$search.'%');
        }

        return $query;
    }

    public function edit(Request $request, $collection, $entry)
    {
        if ($collection->hasStructure() && $request->route()->getName() === 'statamic.cp.collections.entries.edit') {
            return redirect()->to(cp_route('structures.entries.edit', [$collection->handle(), $entry->id(), $entry->slug()]));
        }

        $this->authorize('view', $entry);

        $entry = $entry->fromWorkingCopy();

        $blueprint = $entry->blueprint();

        event(new PublishBlueprintFound($blueprint, 'entry', $entry));

        [$values, $meta] = $this->extractFromFields($entry, $blueprint);

        if ($hasOrigin = $entry->hasOrigin()) {
            [$originValues, $originMeta] = $this->extractFromFields($entry->origin(), $blueprint);
        }

        $viewData = [
            'title' => $entry->value('title'),
            'reference' => $entry->reference(),
            'editing' => true,
            'actions' => [
                'save' => $entry->updateUrl(),
                'publish' => $entry->publishUrl(),
                'revisions' => $entry->revisionsUrl(),
                'restore' => $entry->restoreRevisionUrl(),
                'createRevision' => $entry->createRevisionUrl(),
            ],
            'values' => array_merge($values, ['id' => $entry->id()]),
            'meta' => $meta,
            'collection' => $collection->handle(),
            'blueprint' => $blueprint->toPublishArray(),
            'readOnly' => User::fromUser($request->user())->cant('edit', $entry),
            'locale' => $entry->locale(),
            'localizedFields' => $entry->data()->keys()->all(),
            'isRoot' => $entry->isRoot(),
            'hasOrigin' => $hasOrigin,
            'originValues' => $originValues ?? null,
            'originMeta' => $originMeta ?? null,
            'permalink' => $entry->absoluteUrl(),
            'localizations' => $collection->sites()->map(function ($handle) use ($entry) {
                $localized = $entry->in($handle);
                $exists = $localized !== null;
                return [
                    'handle' => $handle,
                    'name' => Site::get($handle)->name(),
                    'active' => $handle === $entry->locale(),
                    'exists' => $exists,
                    'root' => $exists ? $localized->isRoot() : false,
                    'origin' => $exists ? $localized->id() === optional($entry->origin())->id() : null,
                    'published' => $exists ? $localized->published() : false,
                    'url' => $exists ? $localized->editUrl() : null,
                ];
            })->all(),
            'hasWorkingCopy' => $entry->hasWorkingCopy(),
            'preloadedAssets' => $this->extractAssetsFromValues($values),
            'revisionsEnabled' => $entry->revisionsEnabled(),
            'breadcrumbs' => $this->breadcrumbs($collection),
        ];

        if ($request->wantsJson()) {
            return collect($viewData);
        }

        if ($request->has('created')) {
            session()->now('success', __('Entry created'));
        }

        return view('statamic::entries.edit', array_merge($viewData, [
            'entry' => $entry
        ]));
    }

    public function update(Request $request, $collection, $entry)
    {
        $this->authorize('update', $entry);

        $entry = $entry->fromWorkingCopy();

        $fields = $entry->blueprint()->fields()->addValues($request->except('id'));

        $fields->validate([
            'title' => 'required|min:3',
            'slug' => 'required|alpha_dash',
            'slug' => 'required|alpha_dash|unique_entry_value:'.$collection->handle().','.$entry->id(),
        ]);

        $values = $fields->process()->values();

        $parent = $values->pull('parent');

        $values = $values->except(['slug', 'date']);

        if ($entry->hasOrigin()) {
            $entry->data($values->only($request->input('_localized')));
        } else {
            $entry->merge($values);
        }

        $entry->slug($request->slug);

        if ($entry->collection()->dated()) {
            $entry->date($this->formatDateForSaving($request->date));
        }

        if ($entry->revisionsEnabled() && $entry->published()) {
            $entry
                ->makeWorkingCopy()
                ->user(User::fromUser($request->user()))
                ->save();
        } else {
            if (! $entry->revisionsEnabled()) {
                $entry->published($request->published);
            }

            $entry
                ->set('updated_by', User::fromUser($request->user())->id())
                ->set('updated_at', now()->timestamp)
                ->save();
        }

        if ($parent && ($structure = $collection->structure())) {
            $structure
                ->in($entry->locale())
                ->move($entry->id(), $parent)
                ->save();
        }

        return $entry->fresh()->toArray();
    }

    public function create(Request $request, $collection, $site)
    {
        $this->authorize('create', [EntryContract::class, $collection]);

        $blueprint = $request->blueprint
            ? $collection->ensureEntryBlueprintFields(Blueprint::find($request->blueprint))
            : $collection->entryBlueprint();

        if (! $blueprint) {
            throw new \Exception('A valid blueprint is required.');
        }

        $values = [];

        if ($collection->hasStructure() && $request->parent) {
            $values['parent'] = $request->parent;
        }

        $fields = $blueprint
            ->fields()
            ->addValues($values)
            ->preProcess();

        $values = $fields->values()->merge([
            'title' => null,
            'slug' => null,
            'published' => $collection->defaultPublishState()
        ]);

        $viewData = [
            'title' => __('Create Entry'),
            'actions' => [
                'save' => cp_route('collections.entries.store', [$collection->handle(), $site->handle()])
            ],
            'values' => $values->all(),
            'meta' => $fields->meta(),
            'collection' => $collection->handle(),
            'blueprint' => $blueprint->toPublishArray(),
            'published' => $collection->defaultPublishState(),
            'localizations' => $collection->sites()->map(function ($handle) use ($collection, $site) {
                return [
                    'handle' => $handle,
                    'name' => Site::get($handle)->name(),
                    'active' => $handle === $site->handle(),
                    'exists' => false,
                    'published' => false,
                    'url' => cp_route('collections.entries.create', [$collection->handle(), $handle]),
                ];
            })->all(),
            'revisionsEnabled' => $collection->revisionsEnabled(),
            'breadcrumbs' => $this->breadcrumbs($collection),
        ];

        if ($request->wantsJson()) {
            return collect($viewData);
        }

        return view('statamic::entries.create', $viewData);
    }

    public function store(Request $request, $collection, $site)
    {
        $this->authorize('store', [EntryContract::class, $collection]);

        $blueprint = $collection->ensureEntryBlueprintFields(
            Blueprint::find($request->blueprint)
        );

        $fields = $blueprint->fields()->addValues($request->all());

        $fields->validate([
            'title' => 'required',
            'slug' => 'required|unique_entry_value:'.$collection->handle(),
        ]);

        $values = $fields->process()->values()->except(['slug', 'blueprint']);

        $entry = Entry::make()
            ->collection($collection)
            ->blueprint($request->blueprint)
            ->locale($site->handle())
            ->published($request->get('published'))
            ->slug($request->slug)
            ->data($values);

        if ($collection->dated()) {
            $date = $values['date']
                ? $this->formatDateForSaving($values['date'])
                : now()->format('Y-m-d-Hi');
            $entry->date($date);
        }

        if ($entry->revisionsEnabled()) {
            $entry->store([
                'message' => $request->message,
                'user' => User::fromUser($request->user()),
            ]);
        } else {
            $entry
                ->set('updated_by', User::fromUser($request->user())->id())
                ->set('updated_at', now()->timestamp)
                ->save();
        }

        if ($structure = $collection->structure()) {
            $tree = $structure->in($site->handle());

            if ($request->parent) {
                $tree->appendTo($values['parent'], $entry);
            } else {
                $tree->append($entry);
            }

            $tree->save();
        }

        return array_merge($entry->toArray(), [
            'redirect' => $entry->editUrl(),
        ]);
    }

    public function destroy($collection, $entry)
    {
        if (! $entry = Entry::find($entry)) {
            return $this->pageNotFound();
        }

        $this->authorize('delete', $entry);

        $entry->delete();

        return response('', 204);
    }

    protected function extractFromFields($entry, $blueprint)
    {
        $values = $entry->values()->all();

        if ($entry->hasStructure()) {
            $values['parent'] = array_filter([optional($entry->parent())->id()]);
        }

        $fields = $blueprint
            ->fields()
            ->addValues($values)
            ->preProcess();

        $values = $fields->values()->merge([
            'title' => $entry->value('title'),
            'slug' => $entry->slug(),
            'published' => $entry->published(),
        ]);

        if ($entry->collection()->dated()) {
            $datetime = substr($entry->date()->toDateTimeString(), 0, 16);
            $datetime = ($entry->hasTime()) ? $datetime : substr($datetime, 0, 10);
            $values['date'] = $datetime;
        }

        return [$values->all(), $fields->meta()];
    }

    protected function extractAssetsFromValues($values)
    {
        return collect($values)
            ->filter(function ($value) {
                return is_string($value);
            })
            ->map(function ($value) {
                preg_match_all('/"asset::([^"]+)"/', $value, $matches);
                return str_replace('\/', '/', $matches[1]) ?? null;
            })
            ->flatten(2)
            ->unique()
            ->map(function ($id) {
                return Asset::find($id);
            })
            ->filter()
            ->values();
    }

    protected function formatDateForSaving($date)
    {
        // If there's a time, adjust the format into a datetime order string.
        if (strlen($date) > 10) {
            $date = str_replace(':', '', $date);
            $date = str_replace(' ', '-', $date);
        }

        return $date;
    }

    protected function breadcrumbs($collection)
    {
        return new Breadcrumbs([
            [
                'text' => $collection->hasStructure() ? __('Structures') : __('Collections'),
                'url' => $collection->hasStructure() ? cp_route('structures.index') : cp_route('collections.index'),
            ],
            [
                'text' => $collection->title(),
                'url' => $collection->hasStructure() ? $collection->structure()->showUrl() : $collection->showUrl(),
            ]
        ]);
    }
}
