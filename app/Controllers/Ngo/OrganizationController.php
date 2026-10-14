<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Database;
use App\Core\Response;
use App\Core\TenantContext;
use App\Exceptions\DomainException;
use App\Exceptions\LimitExceededException;
use App\Services\StorageService;
use App\Services\TenantService;

/** The NGO's own profile and organization-level settings (internal Fameweb notes are never shown). */
final class OrganizationController extends NgoController
{
    public function show(): string
    {
        $t = TenantContext::tenant();
        return $this->view('ngo/organization', [
            'title' => 'Organization',
            't' => $t,
            'settings' => TenantService::settings((int) $t['id']),
            'timezones' => ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'America/New_York', 'UTC'],
        ]);
    }

    public function update(): Response
    {
        $tid = $this->tenantId();
        $data = $this->validate([
            'address' => 'required|max:1000',
            'contact_name' => 'required|max:120',
            'email' => 'required|email',
            'phone' => 'required|phone',
            'website' => 'url|max:255',
            'registration_number' => 'max:100',
            'display_name' => 'max:190',
            'notification_email' => 'email',
            'timezone' => 'required|in:Asia/Kolkata,Asia/Dubai,Asia/Singapore,Europe/London,America/New_York,UTC',
            'date_format' => 'required|in:d M Y,d/m/Y,Y-m-d,m/d/Y',
            'primary_color' => 'regex:/^#[0-9a-fA-F]{6}$/',
            'member_id_prefix' => 'regex:/^[A-Za-z0-9\-]{1,10}$/',
            'volunteer_id_prefix' => 'regex:/^[A-Za-z0-9\-]{1,10}$/',
            'intern_id_prefix' => 'regex:/^[A-Za-z0-9\-]{1,10}$/',
        ], 'ngo/organization', ['primary_color' => 'Brand colour', 'member_id_prefix' => 'Member ID prefix',
            'volunteer_id_prefix' => 'Volunteer ID prefix', 'intern_id_prefix' => 'Intern ID prefix']);
        foreach (['member_id_prefix', 'volunteer_id_prefix', 'intern_id_prefix'] as $k) {
            $data[$k] = strtoupper($data[$k]);
        }
        // The NGO name and reference code are managed by Fameweb.
        TenantService::update($tid, array_intersect_key($data, array_flip(['address', 'contact_name', 'email', 'phone', 'website', 'registration_number'])), null);
        TenantService::saveSettings($tid, array_intersect_key($data, TenantService::DEFAULT_SETTINGS));
        $logo = $this->request->file('logo');
        if ($logo) {
            try {
                TenantService::replaceLogo($tid, $logo);
            } catch (DomainException|LimitExceededException $e) {
                return $this->redirect('ngo/organization', 'error', 'Details saved, but the logo was not: ' . $e->getMessage());
            }
        }
        return $this->redirect('ngo/organization', 'success', 'Organization details saved.');
    }

    public function logo(): Response
    {
        $t = TenantContext::tenant();
        $obj = $t['logo_object_id'] ? Database::instance()->fetch(
            'SELECT * FROM storage_objects WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
            [(int) $t['logo_object_id'], (int) $t['id']]
        ) : null;
        if (!$obj) {
            $this->notFound('logo');
        }
        return StorageService::stream($obj);
    }
}
