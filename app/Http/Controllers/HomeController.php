<?php

namespace App\Http\Controllers;

use App\Support\Home\PresentHome;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The landing page: a read-only summary of the job search, derived from existing records (see PresentHome).
 */
class HomeController extends Controller
{
    public function index(PresentHome $home): Response
    {
        return Inertia::render('home', $home->present());
    }
}
