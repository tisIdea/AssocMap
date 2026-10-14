<?php

// UI field definitions and empty data slots for the admin shell.
$options = [
    'municipality' => [],
    'barangay' => [],
    'association' => [],
    'field_officer' => [],
    'project' => [],
    'program_year' => [],
    'component' => [],
    'sub_component' => [],
    'activity' => [],
    'project_status' => ['Planned', 'Ongoing', 'Completed', 'Suspended', 'Cancelled'],
    'status' => ['Active', 'Inactive'],
    'quarter' => ['Q1', 'Q2', 'Q3', 'Q4'],
    'sex' => ['Female', 'Male'],
    'classification' => ['Fisherfolk', 'Senior Citizen', 'PWD', 'Indigenous People', 'Youth'],
    'fisheries_category' => ['Aquaculture', 'Capture Fisheries', 'Post-Harvest'],
    'commodity' => [],
    'delivery_status' => ['Planned', 'Not Delivered', 'Partially Delivered', 'Delivered'],
];
$field = static fn ($name, $label, $type = 'text', $choices = [], $required = true, $help = '') => compact('name', 'label', 'type', 'choices', 'required', 'help');
$select = static fn ($name, $label, $required = true) => $field($name, $label, 'select', $options[$name] ?? [], $required);
$filter = static fn ($name, $label, $type = 'select', $choices = null) => $field($name, $label, $type, $choices ?? ($options[$name] ?? []), false);
$section = static fn ($title, $fields, $repeater = null, $button = null) => compact('title', 'fields', 'repeater', 'button');
$classification = $section('SAAD classification', [$select('component', 'Component'), $select('sub_component', 'Sub-component'), $select('activity', 'Activity')]);
$geographyFilters = [$filter('municipality', 'Municipality'), $filter('association', 'Association')];
$programFilters = [...$geographyFilters, $filter('program_year', 'Program Year'), $filter('component', 'SAAD Component'), $filter('sub_component', 'SAAD Sub-component'), $filter('activity', 'SAAD Activity')];
$modules = [
    'users' => [
        'title' => 'User Management', 'singular' => 'User', 'icon' => 'users', 'action' => 'Invite User',
        'description' => 'Manage application accounts and access invitations.',
        'columns' => ['name' => 'Name', 'email' => 'Email', 'role' => 'Role', 'association' => 'Association', 'contact_number' => 'Contact Number', 'status' => 'Account Status', 'created_at' => 'Date Created'],
        'filters' => [$filter('role', 'Role', 'select', ['Field Officer', 'Association Member Account', 'System Administrator']), $filter('status', 'Account Status', 'select', ['Active', 'Inactive', 'Pending'])],
        'sections' => [$section('Account information', [$field('role', 'Account Role', 'select', ['Field Officer', 'Association Member Account']), $field('full_name', 'Full Name'), $field('display_name', 'Display Name'), $field('email', 'Email', 'email'), $field('contact_number', 'Contact Number', 'tel'), $select('association', 'Association')])],
    ],
    'areas' => [
        'title' => 'Area Management', 'singular' => 'Area', 'icon' => 'pin',
        'description' => 'Manage municipality and barangay reference locations.',
        'columns' => ['name' => 'Area Name', 'area_type' => 'Type', 'barangay' => 'Barangay', 'municipality' => 'Municipality', 'associations_count' => 'Associations', 'status' => 'Status'],
        'filters' => [
            $filter('area_type', 'Area Type', 'select', ['Municipality', 'City', 'Barangay', 'Specific Area']),
            [...$filter('municipality', 'Municipality'), 'empty_label' => 'All Municipalities'],
            $filter('source', 'Source', 'select', ['PSGC', 'Manual']),
            $filter('status', 'Status'),
        ],
    ],
    'associations' => [
        'title' => 'Association Management', 'singular' => 'Association', 'icon' => 'building', 'action' => 'Add Association',
        'description' => 'Maintain association profiles, locations, and primary Field Officer assignments.',
        'columns' => ['name' => 'Association Name', 'acronym' => 'Acronym', 'municipality' => 'Municipality', 'barangay' => 'Barangay', 'area' => 'Specific Area', 'field_officer' => 'Primary Field Officer', 'joined_at' => 'SAAD Phase II Join Date', 'status' => 'Status'],
        'filters' => [$filter('municipality', 'Municipality'), $filter('barangay', 'Barangay'), $filter('field_officer', 'Field Officer'), $filter('status', 'Status')],
        'sections' => [
            $section('Basic information', [$field('name', 'Association Name'), $field('acronym', 'Acronym', 'text', [], false), $field('area_id', 'Specific Area', 'select')]),
            $section('SAAD information', [$field('saad_phase2_join_date', 'SAAD Phase II Join Date', 'date'), $field('primary_field_officer_id', 'Primary Field Officer', 'select'), $field('status', 'Status', 'select', [['value' => 'active', 'label' => 'Active'], ['value' => 'inactive', 'label' => 'Inactive']])]),
        ],
    ],
    'members' => [
        'title' => 'Member Management', 'singular' => 'Member', 'icon' => 'user', 'readonly' => true,
        'description' => 'Monitor official member records and manage archiving and restoration.',
        'columns' => ['full_name' => 'Member Name', 'association' => 'Association', 'municipality' => 'Municipality', 'barangay' => 'Barangay', 'area' => 'Specific Area', 'sex' => 'Sex', 'position' => 'Position', 'fishr_registration_number' => 'FishR Registration Number', 'date_registered' => 'Date Registered', 'status' => 'Status'],
        'filters' => [$filter('association', 'Association'), $filter('municipality', 'Municipality'), $filter('barangay', 'Barangay'), $filter('sex', 'Sex'), $filter('position', 'Position'), $filter('status', 'Status')],
        'details' => ['full_name' => 'Full Name', 'association' => 'Association', 'municipality' => 'Municipality', 'barangay' => 'Barangay', 'area' => 'Specific Area', 'birth_date' => 'Birth Date', 'sex' => 'Sex', 'contact_number' => 'Contact Number', 'fishr_registration_number' => 'FishR Registration Number', 'position' => 'Position', 'position_other_text' => 'Other Position Text', 'date_registered' => 'Date Registered', 'status' => 'Member Status', 'archived' => 'Archived', 'archived_at' => 'Archived At'],
    ],
    'projects' => [
        'title' => 'Projects', 'singular' => 'Project', 'icon' => 'briefcase', 'action' => 'Add Project',
        'description' => 'Organize livelihood interventions and their project materials.',
        'columns' => ['title' => 'Project Title', 'association' => 'Association', 'municipality' => 'Municipality', 'component' => 'SAAD Classification', 'fisheries_category' => 'Fisheries Category', 'commodity' => 'Commodity', 'program_year' => 'Program Year', 'implementation_date' => 'Implementation Date', 'status' => 'Status'],
        'filters' => [...$programFilters, $filter('fisheries_category', 'Fisheries Category', 'checkboxes'), $filter('commodity', 'Commodity'), $filter('status', 'Project Status', 'checkboxes', $options['project_status'])],
        'sections' => [
            $section('Basic project information', [$select('association', 'Association'), $field('title', 'Project Title'), $field('description', 'Description', 'textarea')]),
            $classification,
            $section('Fisheries information', [$select('fisheries_category', 'Fisheries Category'), $select('commodity', 'Commodity')]),
            $section('Implementation', [$select('program_year', 'Program Year'), $field('implementation_date', 'Implementation Start Date', 'date'), $field('implementation_end_date', 'Implementation End Date', 'date'), $field('status', 'Project Status', 'select', $options['project_status']), $field('remarks', 'Remarks', 'textarea', [], false)]),
            $section('Project materials', [$field('name', 'Item Name'), $field('planned_quantity', 'Planned Quantity', 'number'), $field('delivered_quantity', 'Delivered Quantity', 'number'), $field('unit', 'Unit'), $field('unit_cost', 'Unit Cost', 'number'), $select('delivery_status', 'Delivery Status'), $field('delivery_date', 'Delivery Date', 'date', [], false), $field('remarks', 'Remarks', 'textarea', [], false)], 'materials', 'Add Material'),
        ],
    ],
    'trainings' => [
        'title' => 'Trainings', 'singular' => 'Training', 'icon' => 'graduation', 'action' => 'Add Training',
        'description' => 'Plan training activities and review participant attendance.',
        'columns' => ['title' => 'Training Title', 'association' => 'Association', 'component' => 'SAAD Component', 'activity' => 'SAAD Activity', 'program_year' => 'Program Year', 'start_date' => 'Training Date', 'participants_count' => 'Participants', 'status' => 'Status'],
        'filters' => [...$programFilters, $filter('status', 'Training Status', 'select', ['Planned', 'Ongoing', 'Completed', 'Cancelled'])],
        'sections' => [
            $section('Association and project', [$select('association', 'Association'), $select('project', 'Related Project', false)]), $classification,
            $section('Training information', [$field('title', 'Title'), $field('description', 'Description', 'textarea'), $select('program_year', 'Program Year'), $field('start_date', 'Start Date', 'date'), $field('end_date', 'End Date', 'date'), $field('venue', 'Venue'), $field('conducted_by', 'Conducted By'), $field('training_cost', 'Training Cost', 'number', [], false), $field('status', 'Status', 'select', ['Planned', 'Ongoing', 'Completed', 'Cancelled']), $field('remarks', 'Remarks', 'textarea', [], false)]),
        ],
    ],
    'monitoring' => [
        'title' => 'Monitoring', 'singular' => 'Monitoring Record', 'icon' => 'trend', 'action' => 'Add Monitoring',
        'description' => 'Review project performance, production, gross income, and materials together.',
        'columns' => ['project' => 'Project', 'association' => 'Association', 'municipality' => 'Municipality', 'year' => 'Year', 'quarter' => 'Quarter', 'monitoring_date' => 'Monitoring Date', 'project_status' => 'Project Status', 'site_validated' => 'Site Validated'],
        'filters' => [...$geographyFilters, $filter('project', 'Project'), $filter('year', 'Program Year', 'select', $options['program_year']), $filter('quarter', 'Quarter'), $filter('project_status', 'Project Status'), $filter('site_validated', 'Site Validated', 'select', ['Yes', 'No'])],
        'sections' => [
            $section('Monitoring information', [$select('project', 'Project'), $field('year', 'Year', 'number'), $select('quarter', 'Quarter'), $field('monitoring_date', 'Monitoring Date', 'date'), $field('site_validated', 'Site Validated', 'checkbox', [], false), $select('project_status', 'Project Status')]),
            $section('Production monitoring', [$field('target_output', 'Target Output', 'number'), $field('actual_output', 'Actual Output', 'number'), $field('unit', 'Unit')]),
            $section('Gross income monitoring', [$field('month', 'Month', 'month'), $field('gross_income', 'Gross Income', 'number'), $field('remarks', 'Remarks', 'textarea', [], false)], 'gross_income_records', 'Add Month'),
            $section('Materials monitoring', [$field('project_material', 'Project Material', 'select', []), $field('delivery_status', 'Delivery Status', 'display', [], false, 'Delivery status comes from the selected project material.'), $field('condition', 'Condition', 'select', ['Good', 'Needs Maintenance', 'Damaged', 'For Replacement']), $field('scheduled_maintenance', 'Scheduled Maintenance', 'date', [], false), $field('actual_maintenance', 'Actual Maintenance', 'date', [], false), $field('remarks', 'Remarks', 'textarea', [], false)], 'material_checks', 'Add Material Check'),
            $section('Findings', [$field('issues', 'Issues Encountered', 'textarea', [], false), $field('actions_taken', 'Actions Taken', 'textarea', [], false), $field('remarks', 'Overall Remarks', 'textarea', [], false)]),
        ],
    ],
    'program-activities' => [
        'title' => 'Program Activities', 'singular' => 'Program Activity', 'icon' => 'calendar', 'action' => 'Add Activity',
        'description' => 'Record other SAAD activities, from social preparation to enterprise development.',
        'columns' => ['activity' => 'Activity', 'component' => 'SAAD Component', 'sub_component' => 'Sub-component', 'association' => 'Association', 'project' => 'Related Project', 'program_year' => 'Program Year', 'activity_date' => 'Activity Date', 'status' => 'Status'],
        'filters' => [...$programFilters, $filter('status', 'Status', 'select', ['Planned', 'Ongoing', 'Completed', 'Cancelled'])],
        'sections' => [$classification, $section('Activity information', [$select('association', 'Association', false), $select('project', 'Related Project', false), $field('title', 'Title'), $field('description', 'Description', 'textarea'), $select('program_year', 'Program Year'), $field('activity_date', 'Activity Date', 'date'), $field('venue', 'Venue'), $field('status', 'Status', 'select', ['Planned', 'Ongoing', 'Completed', 'Cancelled']), $field('conducted_by', 'Conducted By'), $field('remarks', 'Remarks', 'textarea', [], false)])],
    ],
    'audit-logs' => [
        'title' => 'Audit Log', 'singular' => 'Audit Event', 'icon' => 'receipt', 'readonly' => true,
        'description' => 'Review a read-only history of system activity.',
        'columns' => ['performed_at' => 'Date & Time', 'actor_user_id' => 'Actor', 'action_type' => 'Action', 'module' => 'Module', 'entity_type' => 'Entity Type', 'entity_id' => 'Record Identifier'],
        'filters' => [$filter('action_type', 'Action', 'text'), $filter('module', 'Module', 'text'), $filter('entity_type', 'Entity Type', 'text'), $filter('actor_user_id', 'Actor Identifier', 'text'), $filter('date_from', 'Date From', 'date'), $filter('date_to', 'Date To', 'date')],
    ],
];
foreach ($modules as $key => &$module) {
    $module['key'] = $key;
    $module['rows'] = [];
    $module['record'] = [];
}
unset($module);
return ['options' => $options, 'modules' => $modules];
