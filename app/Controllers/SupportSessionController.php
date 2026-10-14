<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Services\SupportAccessService;

/** Ends a Fameweb support session and returns to the Fameweb admin panel. */
final class SupportSessionController extends Controller
{
    public function end(): Response
    {
        $imp = SupportAccessService::active();
        if ($imp === null) {
            return $this->redirect('/', 'info', 'There is no support session to end.');
        }
        $admin = SupportAccessService::stop('returned');
        if ($admin === null) {
            return $this->redirect('login', 'warning', 'The support session has ended. Please sign in again.');
        }
        return $this->redirect('admin/ngos/' . (int) $imp['tenant_id'], 'success', 'Support session ended. You are back in Fameweb admin.');
    }
}
