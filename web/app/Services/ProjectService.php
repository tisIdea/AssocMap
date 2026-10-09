<?php

namespace App\Services;

use App\Models\Association;
use App\Models\Commodity;
use App\Models\FisheriesProjectCategory;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectProduct;
use App\Models\SaadActivity;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function create(
        array $data,
        UserProfile $currentUser
    ): Project {
        $association = $this->resolveAssociationForUser(
            $data['association_id'],
            $currentUser
        );

        $category = $this->resolveCategory(
            (int) $data['fisheries_category_id']
        );

        $saadActivity =
            $this->resolveLivelihoodProjectActivity();

        return DB::transaction(function () use (
            $data,
            $currentUser,
            $association,
            $category,
            $saadActivity
        ) {
            $commodity = $this->resolveCommodity(
                $data,
                $category,
                $currentUser
            );

            $project = Project::create([
                'association_id' =>
                    $association->id,

                'saad_activity_id' =>
                    $saadActivity->id,

                'fisheries_category_id' =>
                    $category->id,

                'commodity_id' =>
                    $commodity?->id,

                'title' =>
                    $data['title'],

                'description' =>
                    $data['description'] ?? null,

                'program_year' =>
                    $data['program_year'],

                'implementation_start_date' =>
                    $data['implementation_start_date'],

                'implementation_end_date' =>
                    $data['implementation_end_date'] ?? null,

                'status' =>
                    $data['status'],

                'remarks' =>
                    $data['remarks'] ?? null,

                'created_by' =>
                    $currentUser->id,

                'updated_by' =>
                    $currentUser->id,
            ]);

            $this->createMaterials(
                $project,
                $data['materials'] ?? []
            );

            $this->createProducts(
                $project,
                $data['products'] ?? []
            );

            return $project->load([
                'association',
                'saadActivity',
                'fisheriesCategory',
                'commodity',
                'materials',
                'products',
            ]);
        });
    }

    public function update(
        Project $project,
        array $data,
        UserProfile $currentUser
    ): Project {
        $this->ensureUserCanManageProject(
            $project,
            $currentUser
        );

        if ($project->archived_at !== null) {
            throw ValidationException::withMessages([
                'project' =>
                    'Archived projects cannot be edited.',
            ]);
        }

        $association = $this->resolveAssociationForUser(
            $data['association_id'],
            $currentUser
        );

        /*
        |--------------------------------------------------------------------------
        | Protect historical Monitoring scope
        |--------------------------------------------------------------------------
        |
        | Once a Project already has Monitoring records, moving that Project
        | to another Association would also change the Association context of
        | its historical Monitoring records.
        |
        */

        if (
            $project->association_id !== $association->id
            && $this->projectHasMonitoring($project)
        ) {
            throw ValidationException::withMessages([
                'association_id' =>
                    'The association cannot be changed because this project already has monitoring records.',
            ]);
        }

        $category = $this->resolveCategory(
            (int) $data['fisheries_category_id']
        );

        $saadActivity =
            $this->resolveLivelihoodProjectActivity();

        return DB::transaction(function () use (
            $project,
            $data,
            $currentUser,
            $association,
            $category,
            $saadActivity
        ) {
            $commodity = $this->resolveCommodity(
                $data,
                $category,
                $currentUser
            );

            $project->update([
                'association_id' =>
                    $association->id,

                'saad_activity_id' =>
                    $saadActivity->id,

                'fisheries_category_id' =>
                    $category->id,

                'commodity_id' =>
                    $commodity?->id,

                'title' =>
                    $data['title'],

                'description' =>
                    $data['description'] ?? null,

                'program_year' =>
                    $data['program_year'],

                'implementation_start_date' =>
                    $data['implementation_start_date'],

                'implementation_end_date' =>
                    $data['implementation_end_date'] ?? null,

                'status' =>
                    $data['status'],

                'remarks' =>
                    $data['remarks'] ?? null,

                'updated_by' =>
                    $currentUser->id,
            ]);

            if (array_key_exists('materials', $data)) {
                $this->syncMaterials(
                    $project,
                    $data['materials'] ?? []
                );
            }

            if (array_key_exists('products', $data)) {
                $this->syncProducts(
                    $project,
                    $data['products'] ?? []
                );
            }

            return $project->load([
                'association',
                'saadActivity',
                'fisheriesCategory',
                'commodity',
                'materials',
                'products',
            ]);
        });
    }

    public function archive(
        Project $project,
        UserProfile $currentUser
    ): void {
        $this->ensureUserCanManageProject(
            $project,
            $currentUser
        );

        if ($project->archived_at !== null) {
            return;
        }

        $project->update([
            'archived_at' =>
                now(),

            'archived_by' =>
                $currentUser->id,

            'updated_by' =>
                $currentUser->id,
        ]);
    }

    public function unarchive(
        Project $project,
        UserProfile $currentUser
    ): void {
        $this->ensureUserCanManageProject(
            $project,
            $currentUser
        );

        if ($project->archived_at === null) {
            return;
        }

        $project->update([
            'archived_at' =>
                null,

            'archived_by' =>
                null,

            'updated_by' =>
                $currentUser->id,
        ]);
    }

    private function resolveAssociationForUser(
        string $associationId,
        UserProfile $currentUser
    ): Association {
        $association = Association::query()
            ->where('id', $associationId)
            ->whereNull('archived_at')
            ->where('status', 'active')
            ->first();

        if (! $association) {
            throw ValidationException::withMessages([
                'association_id' =>
                    'The selected association is not active or available.',
            ]);
        }

        $roleCode =
            $currentUser->role?->code;

        if ($roleCode === 'system_admin') {
            return $association;
        }

        if ($roleCode === 'field_officer') {
            if (
                $association->primary_field_officer_id
                !== $currentUser->id
            ) {
                throw ValidationException::withMessages([
                    'association_id' =>
                        'You can only manage projects for associations assigned to you.',
                ]);
            }

            return $association;
        }

        throw ValidationException::withMessages([
            'authorization' =>
                'You are not authorized to manage projects.',
        ]);
    }

    private function ensureUserCanManageProject(
        Project $project,
        UserProfile $currentUser
    ): void {
        $roleCode =
            $currentUser->role?->code;

        if ($roleCode === 'system_admin') {
            return;
        }

        if ($roleCode === 'field_officer') {
            $isAssigned = Association::query()
                ->where(
                    'id',
                    $project->association_id
                )
                ->where(
                    'primary_field_officer_id',
                    $currentUser->id
                )
                ->exists();

            if ($isAssigned) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'authorization' =>
                'You are not authorized to manage this project.',
        ]);
    }

    private function resolveCategory(
        int $categoryId
    ): FisheriesProjectCategory {
        $category =
            FisheriesProjectCategory::query()
                ->where('id', $categoryId)
                ->where('is_active', true)
                ->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'fisheries_category_id' =>
                    'The selected fisheries category is not active.',
            ]);
        }

        return $category;
    }

    private function resolveLivelihoodProjectActivity(): SaadActivity
    {
        $activity =
            SaadActivity::query()
                ->where('code', 'FPL_LPD')
                ->where('is_active', true)
                ->first();

        if (! $activity) {
            throw ValidationException::withMessages([
                'saad_activity' =>
                    'The Livelihood Project Delivery SAAD activity is unavailable.',
            ]);
        }

        return $activity;
    }

    private function resolveCommodity(
        array $data,
        FisheriesProjectCategory $category,
        UserProfile $currentUser
    ): ?Commodity {
        $commodityId =
            $data['commodity_id'] ?? null;

        $newCommodityName =
            $data['new_commodity_name'] ?? null;

        if ($commodityId && $newCommodityName) {
            throw ValidationException::withMessages([
                'commodity_id' =>
                    'Select an existing commodity or add a new commodity, not both.',

                'new_commodity_name' =>
                    'Select an existing commodity or add a new commodity, not both.',
            ]);
        }

        if ($commodityId) {
            $commodity = Commodity::query()
                ->where('id', $commodityId)
                ->where(
                    'category_id',
                    $category->id
                )
                ->where('is_active', true)
                ->first();

            if (! $commodity) {
                throw ValidationException::withMessages([
                    'commodity_id' =>
                        'The selected commodity does not belong to the selected fisheries category or is inactive.',
                ]);
            }

            return $commodity;
        }

        if ($newCommodityName) {
            $existingCommodity =
                Commodity::query()
                    ->where(
                        'category_id',
                        $category->id
                    )
                    ->whereRaw(
                        'LOWER(name) = LOWER(?)',
                        [$newCommodityName]
                    )
                    ->first();

            if ($existingCommodity) {
                throw ValidationException::withMessages([
                    'new_commodity_name' =>
                        'That commodity already exists under the selected fisheries category.',
                ]);
            }

            return Commodity::create([
                'category_id' =>
                    $category->id,

                'name' =>
                    $newCommodityName,

                'description' =>
                    null,

                'source' =>
                    'custom',

                'is_active' =>
                    true,

                'created_by' =>
                    $currentUser->id,
            ]);
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | PROJECT MATERIALS
    |--------------------------------------------------------------------------
    */

    private function createMaterials(
        Project $project,
        array $materials
    ): void {
        foreach ($materials as $material) {
            $project->materials()->create(
                $this->materialPayload($material)
            );
        }
    }

    private function syncMaterials(
        Project $project,
        array $materials
    ): void {
        $submittedIds = [];

        foreach ($materials as $material) {
            $materialId =
                $material['id'] ?? null;

            if ($materialId) {
                if (in_array(
                    $materialId,
                    $submittedIds,
                    true
                )) {
                    throw ValidationException::withMessages([
                        'materials' =>
                            'The same project material cannot be submitted more than once.',
                    ]);
                }

                $existingMaterial =
                    ProjectMaterial::query()
                        ->where(
                            'id',
                            $materialId
                        )
                        ->where(
                            'project_id',
                            $project->id
                        )
                        ->first();

                if (! $existingMaterial) {
                    throw ValidationException::withMessages([
                        'materials' =>
                            'One of the submitted materials does not belong to this project.',
                    ]);
                }

                $existingMaterial->update(
                    $this->materialPayload(
                        $material
                    )
                );

                $submittedIds[] =
                    $existingMaterial->id;

                continue;
            }

            $createdMaterial =
                $project->materials()->create(
                    $this->materialPayload(
                        $material
                    )
                );

            $submittedIds[] =
                $createdMaterial->id;
        }

        $removedMaterials =
            ProjectMaterial::query()
                ->where(
                    'project_id',
                    $project->id
                )
                ->when(
                    ! empty($submittedIds),
                    fn ($query) =>
                        $query->whereNotIn(
                            'id',
                            $submittedIds
                        )
                )
                ->get();

        foreach ($removedMaterials as $material) {
            $isMonitored =
                DB::table(
                    'monitoring_materials'
                )
                    ->where(
                        'project_material_id',
                        $material->id
                    )
                    ->exists();

            if ($isMonitored) {
                throw ValidationException::withMessages([
                    'materials' =>
                        "The material \"{$material->item_name}\" cannot be removed because it already has monitoring history.",
                ]);
            }

            $material->delete();
        }
    }

    private function materialPayload(
        array $material
    ): array {
        return [
            'item_name' =>
                trim(
                    $material['item_name']
                ),

            'quantity_planned' =>
                $material['quantity_planned'],

            'quantity_delivered' =>
                $material['quantity_delivered']
                    ?? 0,

            'unit' =>
                trim(
                    $material['unit']
                ),

            'unit_cost' =>
                $material['unit_cost']
                    ?? null,

            'delivery_status' =>
                $material['delivery_status'],

            'delivery_date' =>
                $material['delivery_date']
                    ?? null,

            'remarks' =>
                isset($material['remarks'])
                    && $material['remarks'] !== ''
                        ? trim(
                            $material['remarks']
                        )
                        : null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | PROJECT PRODUCTS
    |--------------------------------------------------------------------------
    */

    private function createProducts(
        Project $project,
        array $products
    ): void {
        foreach ($products as $product) {
            $project->products()->create(
                $this->productPayload($product)
            );
        }
    }

    private function syncProducts(
        Project $project,
        array $products
    ): void {
        $submittedIds = [];

        foreach ($products as $product) {
            $productId =
                $product['id'] ?? null;

            if ($productId) {
                if (in_array(
                    $productId,
                    $submittedIds,
                    true
                )) {
                    throw ValidationException::withMessages([
                        'products' =>
                            'The same project product cannot be submitted more than once.',
                    ]);
                }

                $existingProduct =
                    ProjectProduct::query()
                        ->where(
                            'id',
                            $productId
                        )
                        ->where(
                            'project_id',
                            $project->id
                        )
                        ->first();

                if (! $existingProduct) {
                    throw ValidationException::withMessages([
                        'products' =>
                            'One of the submitted products does not belong to this project.',
                    ]);
                }

                $existingProduct->update(
                    $this->productPayload(
                        $product
                    )
                );

                $submittedIds[] =
                    $existingProduct->id;

                continue;
            }

            $createdProduct =
                $project->products()->create(
                    $this->productPayload(
                        $product
                    )
                );

            $submittedIds[] =
                $createdProduct->id;
        }

        $removedProducts =
            ProjectProduct::query()
                ->where(
                    'project_id',
                    $project->id
                )
                ->when(
                    ! empty($submittedIds),
                    fn ($query) =>
                        $query->whereNotIn(
                            'id',
                            $submittedIds
                        )
                )
                ->get();

        foreach ($removedProducts as $product) {
            $isMonitored =
                DB::table(
                    'monitoring_production'
                )
                    ->where(
                        'project_product_id',
                        $product->id
                    )
                    ->exists();

            if ($isMonitored) {
                throw ValidationException::withMessages([
                    'products' =>
                        "The product \"{$product->product_name}\" cannot be removed because it already has monitoring history.",
                ]);
            }

            $product->delete();
        }
    }

    private function productPayload(
        array $product
    ): array {
        return [
            'product_name' =>
                trim(
                    $product['product_name']
                ),

            'product_type' =>
                $product['product_type'],

            'default_unit' =>
                isset(
                    $product['default_unit']
                )
                && $product['default_unit'] !== ''
                    ? trim(
                        $product['default_unit']
                    )
                    : null,

            'description' =>
                isset(
                    $product['description']
                )
                && $product['description'] !== ''
                    ? trim(
                        $product['description']
                    )
                    : null,

            'is_active' =>
                array_key_exists(
                    'is_active',
                    $product
                )
                    ? (bool) $product['is_active']
                    : true,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MONITORING PROTECTION
    |--------------------------------------------------------------------------
    */

    private function projectHasMonitoring(
        Project $project
    ): bool {
        return DB::table(
            'monitoring_records'
        )
            ->where(
                'project_id',
                $project->id
            )
            ->exists();
    }
}