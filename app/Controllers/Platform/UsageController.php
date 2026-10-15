<?php
declare(strict_types=1);

namespace App\Controllers\Platform;

use App\Core\Database;
use App\Services\UsageService;

final class UsageController extends PlatformController
{
    public function index(): string
    {
        $q = $this->request->str('q');
        $rows = UsageService::platformOverview($q);
        if ($this->request->str('alerts') === '1') {
            $rows = array_values(array_filter($rows, static fn ($r) => $r['alert']));
        }
        $db = Database::instance();
        return $this->view('platform/usage', [
            'title' => 'Platform usage',
            'rows' => $rows,
            'q' => $q,
            'alertsOnly' => $this->request->str('alerts') === '1',
            'totals' => [
                'users' => (int) $db->fetchColumn("SELECT COUNT(*) FROM users WHERE user_type = 'tenant' AND status IN ('active','invited')"),
                'storage' => (int) $db->fetchColumn('SELECT COALESCE(SUM(size_bytes),0) FROM storage_objects WHERE deleted_at IS NULL'),
                'files' => (int) $db->fetchColumn('SELECT COUNT(*) FROM storage_objects WHERE deleted_at IS NULL'),
                'emails' => (int) $db->fetchColumn("SELECT COALESCE(SUM(quantity),0) FROM usage_records WHERE metric = 'emails' AND occurred_at >= ?", [date('Y-m-01 00:00:00')]),
                'members' => (int) $db->fetchColumn("SELECT COUNT(*) FROM people WHERE status IN ('pending','active')"),
            ],
        ]);
    }
}
