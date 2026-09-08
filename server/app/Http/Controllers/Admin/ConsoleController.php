<?php

namespace App\Http\Controllers\Admin;

use App\Admin\ConsoleModules;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The console's landing page — the shell, with nothing in it but the module list.
 *
 * It renders `App\Admin\ConsoleModules` and holds no knowledge of any individual module, which is
 * what makes adding `card#9072`'s floors module an entry in that list rather than an edit here.
 */
class ConsoleController extends Controller
{
    public function index(): View
    {
        return view('console.index', ['modules' => ConsoleModules::all()]);
    }
}
