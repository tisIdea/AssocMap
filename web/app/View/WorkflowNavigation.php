<?php

namespace App\View;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class WorkflowNavigation
{
    public function to(string $name, array $parameters = [], ?string $fragment = null): string
    {
        return route($name, $parameters + ['return_to' => $this->current(request()).($fragment ? '#'.$fragment : '')]);
    }

    public function current(Request $request): string
    {
        $name = $request->route()->getName();
        $query = $this->clean($name, $request->query()) ?? [];
        $url = route($name, array_replace($request->route()->parameters(), $query), false);
        if (strlen($url) > 4096) {
            unset($query['return_to']);
            $url = route($name, array_replace($request->route()->parameters(), $query), false);
        }

        return $url;
    }

    public function carry(Request $request): array
    {
        $value = $request->query('return_to');

        return $this->relative($value) ? ['return_to' => $value] : [];
    }

    public function back(Request $request, string $name, array $parameters = [], array $parents = []): array
    {
        $fallback = ['url' => route($name, $parameters), 'label' => $this->label($name), 'contextual' => false];
        $candidate = $request->query('return_to');
        if (! $this->relative($candidate)) {
            return $fallback;
        }
        $parts = parse_url($candidate);
        parse_str($parts['query'] ?? '', $query);
        foreach ([['name' => $name, 'parameters' => $parameters, 'label' => $fallback['label']], ...$parents] as $target) {
            $base = route($target['name'], $target['parameters'], false);
            if (($parts['path'] ?? '') !== parse_url($base, PHP_URL_PATH)) {
                continue;
            }
            $clean = $this->clean($target['name'], $query);
            if ($clean === null) {
                return $fallback;
            }
            $routeKeys = app('router')->getRoutes()->getByName($target['name'])->parameterNames();
            $routeParameters = array_intersect_key($target['parameters'], array_flip($routeKeys));
            $url = route($target['name'], array_replace($routeParameters, $clean));
            $fragment = $parts['fragment'] ?? '';
            if (in_array($fragment, ['related-association-records', 'project-monitoring', 'project-trainings', 'project-activities'], true)) {
                $url .= '#'.$fragment;
            }

            return ['url' => $url, 'label' => $target['label'], 'contextual' => true];
        }

        return $fallback;
    }

    public function parent(Request $request, string $name, array $parameters = []): string
    {
        return $this->back($request, $name, $parameters)['url'];
    }

    private function relative(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 4096 && str_starts_with($value, '/')
            && ! str_starts_with($value, '//') && ! preg_match('/[\\\\\x00-\x1f\x7f]/', $value)
            && parse_url($value) !== false;
    }

    private function clean(string $name, array $query): ?array
    {
        $page = ['nullable', 'integer', 'min:1', 'max:1000000'];
        $rules = ['return_to' => ['nullable', 'string', 'max:4096']];
        if (preg_match('/\.(associations|association-officers)\.(index|show)$/', $name)) {
            $rules += ['q' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['all', 'active', 'inactive', 'archived'])],
                'municipality' => ['nullable', 'string', 'max:150'], 'barangay' => ['nullable', 'string', 'max:150'],
                'field_officer' => ['nullable', 'string', 'max:255'], 'sort' => ['nullable', Rule::in(['az', 'za', 'newest', 'oldest', 'municipality', 'barangay', 'field_officer', 'status'])],
                'page' => $page, 'related_page' => $page, 'officers_page' => $page, 'members_page' => $page,
                'section' => ['nullable', Rule::in(['members', 'projects', 'trainings', 'activities', 'registrations'])], 'origin' => ['nullable', Rule::in(['register', 'officers'])]];
        } elseif (str_contains($name, '.monitoring.')) {
            $rules += ['search' => ['nullable', 'string', 'max:255'], 'year' => ['nullable', 'integer', 'between:2000,2100'],
                'quarter' => ['nullable', 'integer', 'between:1,4'], 'validated' => ['nullable', 'boolean'],
                'sort' => ['nullable', Rule::in(['newest', 'oldest', 'quarter'])], 'page' => $page];
            if (str_ends_with($name, '.visits')) {
                unset($rules['year'], $rules['quarter']);
                $rules['sort'] = ['nullable', Rule::in(['newest', 'oldest'])];
            }
        } elseif (str_ends_with($name, '.index')) {
            $own = str_starts_with($name, 'association.');
            $projects = str_contains($name, '.projects.');
            $members = str_contains($name, '.members.');
            $statuses = $projects ? ['planned', 'ongoing', 'suspended', 'cancelled', 'completed', 'terminated', 'archived']
                : ($members ? ['active', 'inactive', 'archived'] : (str_contains($name, '.trainings.') ? ['scheduled', 'completed', 'cancelled', 'archived'] : ['planned', 'ongoing', 'completed', 'cancelled', 'archived']));
            $rules += [$own ? 'search' : 'q' => ['nullable', 'string', 'max:120'], 'year' => ['nullable', 'integer', 'between:2000,2100'], 'page' => $page];
            if ($members) {
                unset($rules['year']);
            }
            if (! $own || $projects) {
                $rules['status'] = ['nullable', Rule::in($statuses)];
            }
            if (! $own) {
                $rules['association'] = $members ? ['nullable', 'uuid'] : ['nullable', 'string', 'max:150'];
                $rules['sort'] = ['nullable', Rule::in($members ? ['az', 'za', 'newest', 'oldest'] : ['year', 'az', 'za', 'newest', 'oldest'])];
            }
        } else {
            foreach (['monitoring_page', 'training_page', 'activity_page', 'participant_page', 'products_page', 'materials_page', 'page'] as $key) {
                $rules[$key] = $page;
            }
        }
        $validator = Validator::make($query, $rules);

        return $validator->fails() ? null : array_filter($validator->validated(), fn ($value) => $value !== null && $value !== '');
    }

    private function label(string $name): string
    {
        return match (true) {
            str_contains($name, '.association-officers.') => 'Association Officers',
            str_contains($name, '.associations.') => str_starts_with($name, 'admin.') ? 'Associations' : 'Assigned Associations',
            str_contains($name, '.program-activities.') => 'Program Activities',
            str_contains($name, '.trainings.') => 'Trainings',
            str_contains($name, '.monitoring.') => 'Monitoring Dashboard',
            str_contains($name, '.members.') => 'Member Management',
            default => str_starts_with($name, 'association.') ? 'My Projects' : 'Projects',
        };
    }
}
