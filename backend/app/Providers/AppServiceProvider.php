<?php

namespace App\Providers;

use App\Models\QuestionPaper;
use App\Models\SchoolClass;
use App\Policies\ClassPolicy;
use App\Policies\QuestionPaperPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // SchoolClass -> ClassPolicy doesn't match Laravel's {Model}Policy
        // auto-discovery convention ("SchoolClassPolicy"), since "Class" is
        // reserved and can't be the model's own name.
        Gate::policy(SchoolClass::class, ClassPolicy::class);

        // Register QuestionPaper policy explicitly to ensure it's discovered
        Gate::policy(QuestionPaper::class, QuestionPaperPolicy::class);
    }
}
