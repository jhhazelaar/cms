<?php

namespace Statamic\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Statamic\Console\EnhancesCommands;
use Statamic\Console\RunsInPlease;
use Statamic\Entries\Collection as EntriesCollection;
use Statamic\Entries\Entry;
use Statamic\Facades;
use Statamic\Facades\Site;
use Statamic\Facades\URL;
use Statamic\Http\Controllers\FrontendController;
use Statamic\StaticCaching\Cacher as StaticCacher;
use Statamic\Support\Str;
use Statamic\Support\Traits\Hookable;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;

class StaticWarm extends Command
{
    use EnhancesCommands;
    use Hookable;
    use RunsInPlease;

    protected $signature = 'statamic:static:warm
        {--queue : Queue the requests}
        {--u|user= : HTTP authentication user}
        {--p|password= : HTTP authentication password}
        {--insecure : Skip SSL verification}
    ';

    protected $description = 'Warms the static cache by visiting all URLs';

    protected $shouldQueue = false;

    private $uris;

    public function handle()
    {
        if (! config('statamic.static_caching.strategy')) {
            $this->components->error('Static caching is not enabled.');

            return 1;
        }

        $this->shouldQueue = $this->option('queue');

        if ($this->shouldQueue && config('queue.default') === 'sync') {
            $this->components->error('The queue connection is set to "sync". Queueing will be disabled.');
            $this->shouldQueue = false;
        }

        $this->comment('Please wait. This may take a while if you have a lot of content.');

        $this->warm();

        $this->components->info(
            $this->shouldQueue
                ? 'All requests to warm the static cache have been added to the queue.'
                : 'The static cache has been warmed.'
        );

        return 0;
    }

    private function warm(): void
    {
        $this->output->newLine();
        $this->line('Compiling URLs...');

        $requests = $this->requests();

        $this->output->newLine();

        if ($this->shouldQueue) {
            $queue = config('statamic.static_caching.warm_queue');
            $this->line(sprintf('Adding %s requests onto %squeue...', count($requests), $queue ? $queue.' ' : ''));

            foreach ($requests as $request) {
                StaticWarmJob::dispatch($request, $this->clientConfig())->onQueue($queue);
            }
        } else {
            $this->line('Visiting '.count($requests).' URLs...');

            $pool = new Pool($this->client(), $requests, [
                'concurrency' => $this->concurrency(),
                'fulfilled' => [$this, 'outputSuccessLine'],
                'rejected' => [$this, 'outputFailureLine'],
            ]);

            $promise = $pool->promise();

            $promise->wait();
        }
    }

    private function warmPaginatedPages(string $url, int $currentPage, int $totalPages, string $pageName): void
    {
        $urls = collect(range($currentPage, $totalPages))->map(function ($page) use ($url, $pageName) {
            return "{$url}?{$pageName}={$page}";
        });

        $requests = $urls->map(fn (string $url) => new Request('GET', $url))->all();

        $pool = new Pool($this->client(), $requests, [
            'concurrency' => $this->concurrency(),
            'fulfilled' => function (Response $response, $index) use ($urls) {
                $this->components->twoColumnDetail($this->getRelativeUri($urls->get($index)), '<info>✓ Cached</info>');
            },
            'rejected' => [$this, 'outputFailureLine'],
        ]);

        $promise = $pool->promise();

        $promise->wait();
    }

    private function client(): Client
    {
        return new Client([
            'verify' => $this->shouldVerifySsl(),
            'auth' => $this->option('user') && $this->option('password')
                ? [$this->option('user'), $this->option('password')]
                : null,
        ]);
    }

    private function concurrency(): int
    {
        $strategy = config('statamic.static_caching.strategy');

        return config("statamic.static_caching.strategies.$strategy.warm_concurrency", 25);
    }

    private function clientConfig(): array
    {
        return [
            'verify' => $this->shouldVerifySsl(),
            'auth' => $this->option('user') && $this->option('password')
                ? [$this->option('user'), $this->option('password')]
                : null,
        ];
    }

    public function outputSuccessLine(Response $response, $index): void
    {
        $this->components->twoColumnDetail($this->getRelativeUri($this->uris()->get($index)), '<info>✓ Cached</info>');

        if ($response->hasHeader('X-Statamic-Pagination')) {
            [$currentPage, $totalPages, $pageName] = $response->getHeader('X-Statamic-Pagination');

            $this->warmPaginatedPages($this->uris()->get($index), $currentPage, $totalPages, $pageName);
        }
    }

    public function outputFailureLine($exception, $index): void
    {
        $uri = $this->getRelativeUri($this->uris()->get($index));

        if ($exception instanceof RequestException && $exception->hasResponse()) {
            $response = $exception->getResponse();

            $message = $response->getStatusCode().' '.$response->getReasonPhrase();

            if ($response->getStatusCode() == 500) {
                $message .= "\n".Message::bodySummary($response, 500);
            }
        } else {
            $message = $exception->getMessage();
        }

        $this->components->twoColumnDetail($uri, "<fg=cyan>$message</fg=cyan>");
    }

    private function getRelativeUri(string $uri): string
    {
        return Str::start(Str::after($uri, config('app.url')), '/');
    }

    private function requests()
    {
        return $this->uris()->map(function ($uri) {
            return new Request('GET', $uri);
        })->all();
    }

    private function uris(): Collection
    {
        if ($this->uris) {
            return $this->uris;
        }

        $cacher = app(StaticCacher::class);

        return $this->uris = collect()
            ->merge($this->entryUris())
            ->merge($this->taxonomyUris())
            ->merge($this->termUris())
            ->merge($this->customRouteUris())
            ->merge($this->additionalUris())
            ->unique()
            ->reject(function ($uri) use ($cacher) {
                Site::resolveCurrentUrlUsing(fn () => $uri);

                return $cacher->isExcluded($uri);
            })
            ->sort()
            ->values();
    }

    private function shouldVerifySsl(): bool
    {
        if ($this->option('insecure')) {
            return false;
        }

        return ! $this->laravel->isLocal();
    }

    protected function entryUris(): Collection
    {
        $this->line('[ ] Entries...');

        // "Warm" the structure trees
        Facades\Collection::whereStructured()->each(fn ($collection) => $collection->structure()->trees()->each->tree());

        $entries = Facades\Entry::all()->map(function (Entry $entry) {
            if (! $entry->published() || $entry->private()) {
                return null;
            }

            if ($entry->isRedirect()) {
                return null;
            }

            return $entry->absoluteUrl();
        })->filter();

        $this->line("\x1B[1A\x1B[2K<info>[✔]</info> Entries");

        return $entries;
    }

    protected function taxonomyUris(): Collection
    {
        $this->line('[ ] Taxonomies...');

        $taxonomyUris = Facades\Taxonomy::all()
            ->filter(function ($taxonomy) {
                return view()->exists($taxonomy->template());
            })
            ->flatMap(function (Taxonomy $taxonomy) {
                return $taxonomy->sites()->map(function ($site) use ($taxonomy) {
                    // Needed because Taxonomy uses the current site. If the Taxonomy
                    // class ever gets its own localization logic we can remove this.
                    Site::setCurrent($site);

                    return $taxonomy->absoluteUrl();
                });
            });

        $this->line("\x1B[1A\x1B[2K<info>[✔]</info> Taxonomies");

        return $taxonomyUris;
    }

    protected function termUris(): Collection
    {
        $this->line('[ ] Taxonomy terms...');

        $terms = Facades\Term::all()
            ->merge($this->scopedTerms())
            ->filter(function ($term) {
                return view()->exists($term->template());
            })
            ->flatMap(function (LocalizedTerm $term) {
                return $term->taxonomy()->sites()->map(function ($site) use ($term) {
                    return $term->in($site)->absoluteUrl();
                });
            });

        $this->line("\x1B[1A\x1B[2K<info>[✔]</info> Taxonomy terms");

        return $terms;
    }

    protected function scopedTerms(): Collection
    {
        return Facades\Collection::all()
            ->flatMap(function (EntriesCollection $collection) {
                return $this->getCollectionTerms($collection);
            });
    }

    protected function getCollectionTerms($collection)
    {
        return $collection->taxonomies()
            ->flatMap(function (Taxonomy $taxonomy) {
                return $taxonomy->queryTerms()->get();
            })
            ->map->collection($collection);
    }

    protected function customRouteUris(): Collection
    {
        $this->line('[ ] Custom routes...');

        $action = FrontendController::class.'@route';

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(function (Route $route) use ($action) {
                return $route->getActionName() === $action && ! Str::contains($route->uri(), '{');
            })
            ->map(function (Route $route) {
                return URL::tidy(Str::start($route->uri(), config('app.url').'/'));
            });

        $this->line("\x1B[1A\x1B[2K<info>[✔]</info> Custom routes");

        return $routes;
    }

    protected function additionalUris(): Collection
    {
        $this->line('[ ] Additional...');

        $uris = $this->runHooks('additional', collect());

        $this->line("\x1B[1A\x1B[2K<info>[✔]</info> Additional");

        return $uris->map(fn ($uri) => URL::makeAbsolute($uri));
    }
}
