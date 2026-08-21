@extends('statamic::layout')
@section('title', 'Dexter')

@php
    // Statamic 6's CP is Vue/Inertia and exposes no Blade button component, so
    // buttons are composed from Tailwind against the CP's theme variables --
    // which keeps them correct in both light and dark mode.
    $btn = 'inline-flex items-center justify-center gap-1.5 rounded-md border '
         . 'border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 '
         . 'text-gray-800 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700 '
         . 'text-sm font-medium px-3 py-1.5 cursor-pointer disabled:opacity-50';
    $btnPrimary = 'inline-flex items-center justify-center gap-1.5 rounded-md '
         . 'bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium '
         . 'px-4 py-2 cursor-pointer';
    $btnIcon = 'relative inline-flex items-center justify-center whitespace-nowrap shrink-0 '
         . 'cursor-pointer bg-transparent hover:bg-gray-400/10 dark:hover:bg-white/7 '
         . 'text-gray-900 dark:text-gray-300 size-8 rounded-lg [&_svg]:size-4';

    // Statamic's form-control treatment, taken from the CP's combobox trigger:
    // gradient fill, contrast-aware border, focus ring. appearance-none plus a
    // background chevron because a native <select> cannot host the CP's SVG.
    $select = 'w-full appearance-none antialiased cursor-pointer '
         . 'bg-linear-to-b from-white to-gray-50 text-gray-900 '
         . 'border border-gray-300 with-contrast:border-gray-500 shadow-ui-sm '
         . 'focus-within:focus-outline focus:focus-outline '
         . 'dark:from-gray-850 dark:to-gray-900 dark:border-gray-700 '
         . 'dark:text-gray-300 dark:shadow-ui-md '
         . 'ps-4 pe-9 h-10 text-sm rounded-lg';

    // Chevron drawn as a background image, declared inline rather than with
    // Tailwind arbitrary values: Statamic ships a prebuilt stylesheet that has
    // never scanned this file, so classes like bg-[position:right_0.75rem]
    // simply do not exist and the image lands top-left. Sizing and placement
    // therefore have to travel with the declaration.
    // No quotes inside url(): Blade escapes them to &quot; in an attribute,
    // which breaks the value. The data URI's own attributes use single quotes,
    // so the outer url() can go unquoted.
    $chevron = 'background-image:url(data:image/svg+xml,'
         . rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%239ca3af'><path fill-rule='evenodd' d='M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z' clip-rule='evenodd'/></svg>")
         . ');background-repeat:no-repeat;background-size:1rem 1rem;'
         . 'background-position:right 0.75rem center;';

    $label = 'flex justify-between text-sm font-medium text-gray-925 '
         . 'select-none dark:text-gray-300';

    $description = 'text-sm font-normal text-gray-600/90 dark:text-gray-400 '
         . 'st-text-trim-start';

    // The gray rounded container Statamic wraps its listing tables in.
    // Taken from the rendered CP markup: the table itself stays unstyled
    // beyond .data-table, and this panel supplies the surround.
    $panel = '@container/panel relative bg-gray-150 dark:bg-gray-950/35 dark:inset-shadow-2xs '
         . 'dark:inset-shadow-black w-full rounded-2xl mb-8 max-[600px]:p-1.25 p-1.75 '
         . 'overflow-x-auto';
@endphp

@section('content')

    <div class="max-w-5xl mx-auto">

        <header class="relative flex flex-wrap items-center justify-between gap-4 px-2 sm:px-0 py-6 max-md:pb-8 md:py-8"
                data-ui-header>
            <h1 class="text-[25px] leading-[1.25] st-text-legibility font-medium antialiased flex items-center gap-2.5 md:flex-1">
                <div class="size-5 relative">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                         stroke-linecap="round" stroke-linejoin="round"
                         class="size-5 shrink-0 text-gray-500" aria-hidden="true">
                        <circle cx="11" cy="11" r="7"/>
                        <path d="m20 20-3.9-3.9"/>
                    </svg>
                </div>
                Dexter
            </h1>
            <div class="flex flex-wrap items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span>Indexing on save: <strong>{{ $indexingEnabled ? 'on' : 'off' }}</strong></span>
                <span>Queue: <strong>{{ $queueEnabled ? 'on' : 'off' }}</strong></span>
            </div>
        </header>

        @if (session('success'))
            <div class="rounded-md border border-green-300 bg-green-50 dark:bg-green-900/20 dark:border-green-800 text-green-900 dark:text-green-200 p-3 mb-4 text-sm">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="rounded-md border border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-800 text-red-900 dark:text-red-200 p-3 mb-4 text-sm">
                {{ session('error') }}
            </div>
        @endif

        @foreach ($indexes as $index)
            @if (! $index['configured'] || ! $index['reachable'])
                <div class="rounded-md border border-amber-300 bg-amber-50 dark:bg-amber-900/20 dark:border-amber-800 text-amber-900 dark:text-amber-200 p-3 mb-4 text-sm">
                    <strong>{{ $index['name'] }}</strong>
                    @if (! $index['configured'])
                        has no usable provider — check the driver credentials.
                    @else
                        could not reach its {{ $index['driver'] }} provider.
                    @endif
                    @if ($index['error'])
                        <div class="mt-1 font-mono text-xs opacity-80">{{ $index['error'] }}</div>
                    @endif
                </div>
            @endif
        @endforeach

        {{-- Available indices, straight from the provider --}}
        <div class="mb-8">
            <h2 class="font-medium mb-3">Available Indices</h2>

            @if (count($available))
                {{-- The table stays plain .data-table; the panel supplies the
                     gray surround the header row sits on. This is how the CP's
                     own listings are built. --}}
                <div class="{{ $panel }}" data-ui-panel>
                <table data-size="sm" class="data-table" data-table>
                    <thead>
                        <tr>
                            <th scope="col" data-column="name">Index Name</th>
                            <th scope="col" class="text-end!">Total Documents</th>
                            <th scope="col" class="text-end!">Total Size (bytes)</th>
                            <th scope="col" class="text-end!">Avg Size (bytes)</th>
                            <th scope="col" class="text-end!">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($available as $row)
                            <tr>
                                <td>
                                    <span class="font-mono text-sm">{{ $row['name'] }}</span>
                                    @unless ($row['configured'])
                                        <span class="ms-2 text-xs text-gray-500 dark:text-gray-400"
                                              title="Present on the provider but not in this site's config">
                                            unmanaged
                                        </span>
                                    @endunless
                                </td>
                                <td class="text-end! tabular-nums">{{ number_format($row['documents']) }}</td>
                                <td class="text-end! tabular-nums">{{ number_format($row['size']) }}</td>
                                <td class="text-end! tabular-nums">{{ number_format($row['avg']) }}</td>
                                <td>
                                    <div class="flex items-center justify-end gap-2">
                                        @if ($canManage && $row['configured'])
                                            <form method="POST" action="{{ cp_route('dexter.clear', $row['key']) }}"
                                                  onsubmit="return confirm('Remove all {{ number_format($row['documents']) }} document(s) from {{ $row['name'] }}? The index itself is kept.');">
                                                @csrf
                                                <button type="submit" class="{{ $btnIcon }}" title="Clear all documents">
                                                    {{-- eraser --}}
                                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="m7 21-4.3-4.3a1 1 0 0 1 0-1.4l9.6-9.6a1 1 0 0 1 1.4 0l5.6 5.6a1 1 0 0 1 0 1.4L13 21"/>
                                                        <path d="M22 21H7"/><path d="m5 11 9 9"/>
                                                    </svg>
                                                    <span class="sr-only">Clear {{ $row['name'] }}</span>
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ cp_route('dexter.delete', $row['key']) }}"
                                                  onsubmit="return confirm('Delete the index {{ $row['name'] }} entirely? Its provider settings are destroyed with it. This cannot be undone.');">
                                                @csrf
                                                <button type="submit" class="{{ $btnIcon }} text-red-600 dark:text-red-400" title="Delete index">
                                                    {{-- trash --}}
                                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/>
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                        <path d="M10 11v6"/><path d="M14 11v6"/>
                                                    </svg>
                                                    <span class="sr-only">Delete {{ $row['name'] }}</span>
                                                </button>
                                            </form>
                                        @elseif ($canManage)
                                            {{-- Unmanaged: addressed by resolved name, since no
                                                 config key maps to it. Usually left behind when an
                                                 index gained `sites` and its old unlocalized name
                                                 stopped being written to. --}}
                                            <form method="POST" action="{{ cp_route('dexter.unmanaged.clear') }}"
                                                  onsubmit="return confirm('Remove all {{ number_format($row['documents']) }} document(s) from {{ $row['name'] }}? Dexter does not manage this index.');">
                                                @csrf
                                                <input type="hidden" name="name" value="{{ $row['name'] }}">
                                                <button type="submit" class="{{ $btnIcon }}" title="Clear all documents">
                                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="m7 21-4.3-4.3a1 1 0 0 1 0-1.4l9.6-9.6a1 1 0 0 1 1.4 0l5.6 5.6a1 1 0 0 1 0 1.4L13 21"/>
                                                        <path d="M22 21H7"/><path d="m5 11 9 9"/>
                                                    </svg>
                                                    <span class="sr-only">Clear {{ $row['name'] }}</span>
                                                </button>
                                            </form>

                                            <form method="POST" action="{{ cp_route('dexter.unmanaged.delete') }}"
                                                  onsubmit="return confirm('Delete the index {{ $row['name'] }} entirely? Dexter does not manage it, so nothing will recreate it. This cannot be undone.');">
                                                @csrf
                                                <input type="hidden" name="name" value="{{ $row['name'] }}">
                                                <button type="submit" class="{{ $btnIcon }} text-red-600 dark:text-red-400" title="Delete index">
                                                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/>
                                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                                                        <path d="M10 11v6"/><path d="M14 11v6"/>
                                                    </svg>
                                                    <span class="sr-only">Delete {{ $row['name'] }}</span>
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-gray-600">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            @else
                <div class="{{ $panel }} p-6! text-sm text-gray-500 dark:text-gray-400" data-ui-panel>
                    No indices found. Run a reindex below to create them.
                </div>
            @endif
        </div>

        {{-- Rebuild --}}
        @if ($canManage)
            <div class="card p-0 mb-6 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                    <h2 class="font-medium">Rebuild an index</h2>
                </div>

                <div class="p-4">
                    @if (count($rebuildable))
                        <form method="POST" action="{{ cp_route('dexter.reindex') }}"
                              class="flex flex-wrap items-end gap-4"
                              data-dexter-rebuild>
                            @csrf

                            <div class="min-w-0 flex flex-col gap-2" data-ui-input-group>
                                <div class="flex flex-col gap-1.5" data-ui-field-header>
                                    <label class="{{ $label }}" for="reindex-index" data-ui-label>
                                        <div>Index</div>
                                    </label>
                                    <div class="{{ $description }}" data-ui-description>
                                        Existing documents are updated; anything missing is created.
                                    </div>
                                </div>
                                {{-- One option per index per site, named for the index it
                                     actually writes to. A localized index also gets an
                                     unlocalized option when it holds assets or users,
                                     which have no site and live in the bare name. --}}
                                <select id="reindex-index" name="index" required class="{{ $select }}" style="{{ $chevron }}">
                                        <option value="">Choose index</option>
                                        @foreach ($rebuildable as $target)
                                            <option value="{{ $target['value'] }}">{{ $target['resolved'] }}</option>
                                        @endforeach
                                    </select>
                            </div>

                            <div class="min-w-0 flex flex-col gap-2" data-ui-input-group>
                                <div class="flex flex-col gap-1.5" data-ui-field-header>
                                    <label class="{{ $label }}" for="reindex-searchable" data-ui-label>
                                        <div>Content</div>
                                    </label>
                                    <div class="{{ $description }}" data-ui-description>
                                        Rebuild one source, or everything in the index.
                                    </div>
                                </div>
                                {{-- Empty and disabled until an index is chosen; the script
                                     below fills it from that choice.

                                     It used to render every index's sources so the control
                                     stayed usable without JavaScript. That stopped making
                                     sense once targets became per-site: each locale of an
                                     index contributes the same sources, so the un-narrowed
                                     list showed "Pages" once per site with nothing to tell
                                     the entries apart. An empty control is honest about
                                     needing the index first, and the server still rejects a
                                     source that does not belong to the chosen target. --}}
                                    <select id="reindex-searchable" name="searchable" class="{{ $select }}" style="{{ $chevron }}" disabled>
                                        <option value="">All content</option>
                                    </select>
                            </div>

                            <button type="submit" class="{{ $btnPrimary }}">Start Indexing</button>
                        </form>

                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Nothing is configured to index yet.
                        </p>
                    @endif
                </div>
            </div>

            {{-- Settings transfer.
                 Two forms rather than a button pair per index: choosing the
                 index is the whole interaction, and a row per target repeated
                 the same two controls once per locale. --}}
            @php
                $hasAnyFile = collect($settingsFiles)->flatten()->isNotEmpty();
            @endphp

            <div class="card p-0 mb-6 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                    <h2 class="font-medium">Export Index Settings</h2>
                </div>

                <div class="p-4">
                    <div class="{{ $description }} mb-3">
                        Export the selected index's settings (synonyms, ranking rules, faceting, etc) to a <code>.json</code> file in the
                        @foreach ($settingsDirectories as $driver => $directory)
                            <code class="font-mono text-xs">{{ $directory }}</code>@if (! $loop->last) or @endif
                        @endforeach
                        directory. You can make changes to the settings, then import them to update the settings in the provider.
                    </div>

                    <form method="POST" action="{{ cp_route('dexter.export') }}"
                          class="flex flex-wrap items-end gap-4">
                        @csrf

                        <div class="min-w-0 flex flex-col gap-2" data-ui-input-group>
                            <label class="{{ $label }} sr-only" for="export-index">Index</label>
                            <select id="export-index" name="index" required
                                    class="{{ $select }}" style="{{ $chevron }}">
                                <option value="">Choose wisely</option>
                                @foreach ($rebuildable as $target)
                                    <option value="{{ $target['value'] }}">{{ $target['resolved'] }}</option>
                                @endforeach
                            </select>
                        </div>

                        <button type="submit" class="{{ $btnPrimary }}">Export</button>
                    </form>
                </div>
            </div>

            <div class="card p-0 mb-6 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-800">
                    <h2 class="font-medium">Import Index Settings</h2>
                </div>

                <div class="p-4">
                    @if ($hasAnyFile)
                        <form method="POST" action="{{ cp_route('dexter.import') }}"
                              class="flex flex-wrap items-end gap-4"
                              data-dexter-import>
                            @csrf

                            <div class="min-w-0 flex flex-col gap-2" data-ui-input-group>
                                <div class="flex flex-col gap-1.5" data-ui-field-header>
                                    <label class="{{ $label }}" for="import-index" data-ui-label>
                                        <div>Index</div>
                                    </label>
                                    <div class="{{ $description }}" data-ui-description>
                                        Choose the index which will have the selected settings file applied to.
                                    </div>
                                </div>
                                <select id="import-index" name="index" required
                                        class="{{ $select }}" style="{{ $chevron }}">
                                    <option value="">Choose wisely</option>
                                    @foreach ($rebuildable as $target)
                                        <option value="{{ $target['value'] }}">{{ $target['resolved'] }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="min-w-0 flex flex-col gap-2" data-ui-input-group>
                                <div class="flex flex-col gap-1.5" data-ui-field-header>
                                    <label class="{{ $label }}" for="import-file" data-ui-label>
                                        <div>Settings File</div>
                                    </label>
                                    <div class="{{ $description }}" data-ui-description>
                                        Choose the settings file to apply to the chosen index.
                                    </div>
                                </div>
                                {{-- Rendered with every driver's files, then narrowed to
                                     the chosen index's driver by the script below. Never
                                     disabled: without JavaScript the full list stays
                                     usable, and the action re-checks the file against
                                     the driver's directory either way. --}}
                                <select id="import-file" name="settings" required
                                        class="{{ $select }}" style="{{ $chevron }}">
                                    <option value="">Choose wisely</option>
                                    @foreach ($settingsFiles as $driver => $files)
                                        @foreach ($files as $file)
                                            <option value="{{ $file }}">{{ $file }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </div>

                            <button type="submit" class="{{ $btnPrimary }}">Import</button>
                        </form>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            No settings files found in
                            @foreach ($settingsDirectories as $driver => $directory)
                                <code class="font-mono text-xs">{{ $directory }}</code>@if (! $loop->last) or @endif
                            @endforeach.
                            Export an index above, or commit a file into that directory.
                        </p>
                    @endif
                </div>
            </div>
        @endif

        {{-- Orphans --}}
        @if (count($orphans))
            <div class="rounded-md border border-red-300 bg-red-50 dark:bg-red-900/20 dark:border-red-800 text-red-900 dark:text-red-200 p-4 mb-6">
                <p class="font-medium mb-2">
                    {{ count($orphans) }} configured
                    {{ count($orphans) === 1 ? 'searchable does' : 'searchables do' }} not resolve
                </p>
                <p class="text-sm mb-3">
                    Statamic handles can be renamed, which silently orphans index configuration. Correct each handle in
                    <span class="font-mono">config/statamic/dexter.php</span>.
                </p>
                <ul class="text-sm list-disc ms-5">
                    @foreach ($orphans as $orphan)
                        <li><span class="font-mono">{{ $orphan['key'] }}</span> — {{ $orphan['error'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Configured searchables.
             Collapsed by default: this is reference material, not something an
             operator needs on every visit, and it pushed the actions above it
             off the screen.

             A native <details> rather than a CP component: Statamic 6 ships no
             Blade disclosure, and its Vue one cannot be mounted from here. It
             also needs no JavaScript, so it survives the Vue subtree swap that
             the script at the foot of this file works around, and it is
             keyboard-accessible on its own. --}}
        @if (count($indexes))
            <style>
                /* The default disclosure triangle, replaced by the chevron. */
                [data-dexter-details] > summary { list-style: none; }
                [data-dexter-details] > summary::-webkit-details-marker { display: none; }

                [data-dexter-details-chevron] { transition: transform 150ms ease; }
                [data-dexter-details][open] > summary [data-dexter-details-chevron] {
                    transform: rotate(90deg);
                }
            </style>
        @endif
        @if (count($indexes))
            <details class="mb-8">
                <summary class="flex items-center gap-2 cursor-pointer
                                    rounded-lg -mx-2 px-2 py-1.5
                                    hover:bg-gray-400/10 dark:hover:bg-white/7">
                    {{-- Rotated by the sibling stylesheet when the parent is open. --}}
                    <svg class="size-4 shrink-0 text-gray-500" data-dexter-details-chevron
                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L10.94 10 7.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                    </svg>

                    Review detailed index settings
                </summary>

            @foreach ($indexes as $index)
                @php
                    // An index missing a searchable opens on load. Hiding a broken
                    // configuration behind a closed panel is how it stays broken.
                    $hasMissing = collect($index['searchables'])->contains(fn ($s) => ! $s['exists']);
                @endphp

                <details class="mb-8" data-dexter-details @if ($hasMissing) open @endif>
                    <summary class="flex items-center gap-2 cursor-pointer
                                    rounded-lg -mx-2 px-2 py-1.5
                                    hover:bg-gray-400/10 dark:hover:bg-white/7">
                        {{-- Rotated by the sibling stylesheet when the parent is open. --}}
                        <svg class="size-4 shrink-0 text-gray-500" data-dexter-details-chevron
                             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M7.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L10.94 10 7.22 6.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/>
                        </svg>

                        <div class="min-w-0">
                            <h2 class="font-medium">
                                Configured content — {{ $index['name'] }}
                                @if ($hasMissing)
                                    <span class="ms-1 text-xs font-normal text-red-700 dark:text-red-400">
                                        needs attention
                                    </span>
                                @endif
                            </h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                <span class="font-mono">{{ implode(', ', $index['resolvedNames']) }}</span>
                                &middot; {{ $index['driver'] ?? 'no driver' }}
                                &middot; {{ count($index['searchables']) }}
                                {{ count($index['searchables']) === 1 ? 'searchable' : 'searchables' }}
                            </p>
                        </div>
                    </summary>

                    <div class="{{ $panel }} mt-3" data-ui-panel>
                    <table data-size="sm" class="data-table" data-table>
                        <thead>
                            <tr>
                                <th scope="col">Searchable</th>
                                <th scope="col">Fields</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($index['searchables'] as $s)
                                <tr>
                                    <td class="align-top">
                                        <span class="font-mono text-sm">{{ $s['key'] }}</span>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $s['type'] }}</div>
                                    </td>
                                    <td class="align-top">
                                        @foreach ($s['fields'] as $field)
                                            <div class="font-mono text-xs">
                                                {{ $field }}
                                                @if (str_contains($field, '*'))
                                                    <span class="text-blue-600 dark:text-blue-400">nested</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="align-top">
                                        @if ($s['exists'])
                                            <span class="text-green-700 dark:text-green-400 text-sm">resolved</span>
                                        @else
                                            <span class="text-red-700 dark:text-red-400 text-sm">missing</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </details>
            @endforeach

            <p class="text-xs text-gray-500 dark:text-gray-400">
                Default result limit: {{ $searchLimit }}. Paths marked <span class="text-blue-600 dark:text-blue-400">nested</span>
                reach into Replicator, Bard, Grid and Group content that Statamic's native search cannot index.
            </p>
        @endif

    </div>

@endsection

@section('scripts')
    <script>
        // Delegated from document rather than bound to the elements.
        //
        // The form is rendered inside #statamic, the element the CP's Vue app
        // mounts on. Anything bound directly to those nodes is lost when Vue
        // replaces that subtree, and no amount of retrying helps because the
        // original nodes never come back. A listener on document survives,
        // and resolves the current elements at the moment of the event.
        (function () {
            const map = @json($rebuildableMap);

            function repopulate(indexSelect) {
                const form = indexSelect.closest('[data-dexter-rebuild]');
                const contentSelect = form && form.querySelector('#reindex-searchable');

                if (! contentSelect) return;

                const sources = map[indexSelect.value];
                const previous = contentSelect.value;

                contentSelect.innerHTML = '';
                contentSelect.append(new Option('All content', ''));

                // Back to "Choose index": the sources belonged to the previous
                // choice, so the control returns to its initial empty state
                // rather than keeping a list that no longer applies.
                if (! sources) {
                    contentSelect.disabled = true;

                    return;
                }

                sources.forEach(s => contentSelect.append(new Option(s.label, s.value)));
                contentSelect.disabled = false;

                // Keep the selection when it still belongs to this index.
                if (sources.some(s => s.value === previous)) {
                    contentSelect.value = previous;
                }
            }

            // Import: the settings files belong to a driver, so choosing an
            // index narrows the list to that provider's directory. Same
            // delegation reasoning as above.
            const filesByDriver = @json($settingsFiles);
            const targetDrivers = @json($targetDrivers);

            function repopulateFiles(indexSelect) {
                const form = indexSelect.closest('[data-dexter-import]');
                const fileSelect = form && form.querySelector('#import-file');

                if (! fileSelect) return;

                const files = filesByDriver[targetDrivers[indexSelect.value]];

                // Nothing chosen, or a driver with no directory: leave the
                // full server-rendered list rather than emptying the control.
                if (! files) return;

                const previous = fileSelect.value;
                fileSelect.innerHTML = '';
                fileSelect.append(new Option('Choose wisely', ''));
                files.forEach(f => fileSelect.append(new Option(f, f)));

                if (files.includes(previous)) {
                    fileSelect.value = previous;
                }
            }

            document.addEventListener('change', function (event) {
                const el = event.target;

                if (el && el.id === 'reindex-index') {
                    repopulate(el);
                }

                if (el && el.id === 'import-index') {
                    repopulateFiles(el);
                }
            });

            // Apply once for a pre-selected index, e.g. after a browser
            // restores form state on back-navigation.
            function initial() {
                const el = document.querySelector('#reindex-index');
                if (el && el.value) repopulate(el);
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initial);
            } else {
                initial();
            }
        })();
    </script>
@endsection
