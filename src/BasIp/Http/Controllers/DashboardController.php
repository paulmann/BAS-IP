<?php

declare(strict_types=1);

namespace BasIp\Http\Controllers;

use BasIp\Auth\User;
use BasIp\Core\Request;
use BasIp\Core\Response;
use BasIp\Http\Controller;

/**
 * Role-aware entry point: `/` sends admins to the dashboard, residents to their
 * cabinet and guests to the login screen.
 */
final class DashboardController extends Controller
{
    public function home(Request $request): Response
    {
        $user = $this->user();
        if ($user === null) {
            return $this->redirect('/login');
        }

        return match (true) {
            $user->isAdmin(), $user->isOperator() => $this->redirect('/admin'),
            $user->isResident()                   => $this->redirect('/cabinet'),
            default                               => $this->redirect('/panel'),
        };
    }

    public function index(Request $request): Response
    {
        $user = $this->requireUser();

        if ($user->isResident()) {
            return $this->redirect('/cabinet');
        }

        return $this->redirect('/admin');
    }
}
