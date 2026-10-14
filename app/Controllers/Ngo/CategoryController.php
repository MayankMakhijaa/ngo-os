<?php
declare(strict_types=1);

namespace App\Controllers\Ngo;

use App\Core\Database;
use App\Core\Response;
use App\Exceptions\DomainException;
use App\Services\CategoryService;

/** Categories used to group members, volunteers and interns (e.g. "Field volunteer"). */
final class CategoryController extends NgoController
{
    public function index(): string
    {
        $tid = $this->tenantId();
        $edit = $this->request->int('edit') ? CategoryService::find($tid, $this->request->int('edit')) : null;
        return $this->view('ngo/people/categories', [
            'title' => 'Categories',
            'categories' => Database::instance()->fetchAll(
                'SELECT c.*, (SELECT COUNT(*) FROM people p WHERE p.category_id = c.id) AS people_count
                   FROM person_categories c WHERE c.tenant_id = ? ORDER BY c.name',
                [$tid]
            ),
            'edit' => $edit,
        ]);
    }

    public function store(): Response
    {
        $id = $this->request->int('id');
        $category = $id ? (CategoryService::find($this->tenantId(), $id) ?? $this->notFound('category')) : null;
        try {
            CategoryService::save($this->user(), $category, [
                'name' => $this->request->str('name'),
                'applies_to' => $this->request->str('applies_to'),
                'description' => mb_substr($this->request->str('description'), 0, 255),
            ]);
        } catch (DomainException $e) {
            $this->failValidation([], 'ngo/people-categories' . ($category ? '?edit=' . $category['id'] : ''), $e->getMessage());
        }
        return $this->redirect('ngo/people-categories', 'success', $category ? 'Category saved.' : 'Category added.');
    }

    public function delete(): Response
    {
        $category = CategoryService::find($this->tenantId(), (int) $this->request->param('id')) ?? $this->notFound('category');
        try {
            CategoryService::delete($this->user(), $category);
        } catch (DomainException $e) {
            return $this->redirect('ngo/people-categories', 'error', $e->getMessage());
        }
        return $this->redirect('ngo/people-categories', 'success', 'Category deleted.');
    }
}
