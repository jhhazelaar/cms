<?php

namespace Statamic\CP\Utilities;

use Closure;
use Facades\Statamic\CP\Utilities\CoreUtilities;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\User;

class UtilityRepository
{
    protected $utilities;
    protected $extensions = [];

    public function __construct()
    {
        $this->utilities = collect([]);
    }

    public function boot()
    {
        CoreUtilities::boot();

        foreach ($this->extensions as $callback) {
            $callback($this);
        }

        return $this;
    }

    public function extend(Closure $callback)
    {
        $this->extensions[] = $callback;
    }

    public function make(string $handle)
    {
        return (new Utility)->handle($handle);
    }

    public function register($utility)
    {
        if (! $utility instanceof Utility) {
            $utility = $this->make($utility);
        }

        $this->utilities[$utility->handle()] = $utility;

        return $utility;
    }

    public function all()
    {
        return $this->utilities;
    }

    public function authorized()
    {
        return $this->all()->filter(function ($utility) {
            return User::current()->can("access {$utility->handle()} utility");
        });
    }

    public function find(string $handle)
    {
        return $this->all()->get($handle);
    }

    public function findBySlug(string $slug)
    {
        return $this->all()->first(fn ($utility) => $utility->slug() === $slug);
    }

    public function routes()
    {
        Route::namespace('\\')->prefix('utilities')->name('utilities.')->group(function () {
            $this->boot()->all()->each(function ($utility) {
                if ($utility->action()) {
                    Route::get($utility->slug(), $utility->action())
                        ->middleware("can:access {$utility->handle()} utility")
                        ->name($utility->slug());
                }

                if ($routeClosure = $utility->routes()) {
                    Route::name($utility->slug().'.')
                        ->prefix($utility->slug())
                        ->middleware("can:access {$utility->handle()} utility")
                        ->group(function () use ($routeClosure) {
                            $routeClosure(Route::getFacadeRoot());
                        });
                }
            });
        });
    }
}
