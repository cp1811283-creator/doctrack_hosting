<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\WorkflowStage;
use App\Models\WorkflowStageDepartment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Idempotent by design — updateOrCreate()/firstOrCreate() keyed on the
     * unique columns, not create(). Running `php artisan db:seed` a second
     * time (without a fresh migration first) should always be safe.
     *
     * Real accounts start UNVERIFIED (no email_verified_at), same as any
     * account an admin creates through the UI, and each gets a real
     * verification email sent below. Without that, a fresh install would
     * have no way in at all. See AuthController::login()/verifyEmail() and
     * User::sendEmailVerificationNotification().
     */
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['username' => 'jhoncarl.jamon'],
            [
                'full_name' => 'Jhoncarl Jamon',
                'email' => 'jhoncarl.jamon.ujfcorporation@gmail.com',
                'role' => 'admin',
                'assigned_category' => null,
                'password_hash' => Hash::make('jjamon123'),
                'is_active' => true,
            ]
        );

        // Two originator accounts — any number of originators is
        // supported, there's nothing category/department-specific about
        // the role (see User::ROLES).
        $originators = [
            ['username' => 'christina.villa', 'full_name' => 'Christina Villa', 'email' => 'christina.villa.ujfcorporation@gmail.com', 'password' => 'cvilla123'],
            ['username' => 'louis.suan', 'full_name' => 'Louis Suan', 'email' => 'louis.suan.ujfcorporation@gmail.com', 'password' => 'lsuan123'],
        ];

        $createdOriginators = [];
        foreach ($originators as $definition) {
            $createdOriginators[] = User::updateOrCreate(
                ['username' => $definition['username']],
                [
                    'full_name' => $definition['full_name'],
                    'email' => $definition['email'],
                    'role' => 'originator',
                    'assigned_category' => null,
                    'password_hash' => Hash::make($definition['password']),
                    'created_by' => $admin->user_id,
                    'is_active' => true,
                ]
            );
        }

        // Each approver's own department and level, plus the single category
        // they handle. Heads have no assigned category — they cover every
        // category's Final Approval for their own department (see
        // WorkflowService::eligibleApproversForStage()). $stages lists the
        // "Category:Stage" keys this approver is picked for (none for heads,
        // who get Final Approval by level, not by pick).
        $approvers = [
            ['username' => 'judyann.bron', 'email' => 'judyann.bron.ujfcorporation@gmail.com', 'full_name' => 'Judyann Bron', 'category' => 'Job Order', 'department' => 'Engineering', 'level' => 'staff', 'stages' => ['Job Order:Technical Review']],
            ['username' => 'melissa.jamon', 'email' => 'melissa.jamon.ujfcorporation@gmail.com', 'full_name' => 'Melissa Jamon', 'category' => 'Job Order', 'department' => 'Finance', 'level' => 'staff', 'stages' => ['Job Order:Budget Check']],
            ['username' => 'elmor.castello', 'email' => 'elmor.castello1.ujfcorporation@gmail.com', 'full_name' => 'Elmor Castello', 'category' => null, 'department' => 'Engineering', 'level' => 'head', 'stages' => []],
            ['username' => 'dianara.jamon', 'email' => 'dianara.jamon.ujfcorporationn@gmail.com', 'full_name' => 'Dianara Jamon', 'category' => null, 'department' => 'Finance', 'level' => 'head', 'stages' => []],
            ['username' => 'jeferson.ulnagan', 'email' => 'jeferson.ulnagan.ujfcorporation@gmail.com', 'full_name' => 'Jeferson Ulnagan', 'category' => 'Purchase Requisition', 'department' => 'Finance', 'level' => 'staff', 'stages' => ['Purchase Requisition:Budget Check']],
            ['username' => 'aljun.belarmino', 'email' => 'aljun.belarmino.ujfcorporation@gmail.com', 'full_name' => 'Aljun Belarmino', 'category' => 'Purchase Requisition', 'department' => 'Finance', 'level' => 'staff', 'stages' => ['Purchase Requisition:Procurement Review']],
            ['username' => 'caleb.jamon', 'email' => 'caleb.jamon.ujfcorporation@gmail.com', 'full_name' => 'Caleb Jamon', 'category' => 'Service Report', 'department' => 'Engineering', 'level' => 'staff', 'stages' => ['Service Report:Quality Inspection']],
        ];

        $pipelines = [
            'Job Order' => ['Technical Review', 'Budget Check', 'Final Approval'],
            'Purchase Requisition' => ['Budget Check', 'Procurement Review', 'Final Approval'],
            'Service Report' => ['Quality Inspection', 'Final Approval'],
        ];

        $stagesByName = [];
        foreach ($pipelines as $category => $stages) {
            foreach ($stages as $i => $name) {
                $stage = WorkflowStage::firstOrCreate(
                    ['document_category' => $category, 'sequence_order' => $i + 1],
                    ['stage_name' => $name, 'description' => "{$name} for {$category} documents."]
                );
                $stagesByName["{$category}:{$name}"] = $stage;
            }
        }

        // Which department(s) own each stage. Final Approval is shared by
        // Engineering and Finance in every category, so the same two heads
        // (Elmor Castello, Dianara Jamon) sign off all three.
        $stageDepartments = [
            'Job Order:Technical Review' => ['Engineering'],
            'Job Order:Budget Check' => ['Finance'],
            'Job Order:Final Approval' => ['Engineering', 'Finance'],
            'Purchase Requisition:Budget Check' => ['Finance'],
            'Purchase Requisition:Procurement Review' => ['Finance'],
            'Purchase Requisition:Final Approval' => ['Engineering', 'Finance'],
            'Service Report:Quality Inspection' => ['Engineering'],
            'Service Report:Final Approval' => ['Engineering', 'Finance'],
        ];

        foreach ($stageDepartments as $key => $departments) {
            foreach ($departments as $department) {
                WorkflowStageDepartment::firstOrCreate([
                    'stage_id' => $stagesByName[$key]->stage_id,
                    'department' => $department,
                ]);
            }
        }

        $createdApprovers = [];
        foreach ($approvers as $definition) {
            $user = User::updateOrCreate(
                ['username' => $definition['username']],
                [
                    'full_name' => $definition['full_name'],
                    'email' => $definition['email'],
                    'role' => 'approver',
                    'assigned_category' => $definition['category'],
                    'department' => $definition['department'],
                    'level' => $definition['level'],
                    'password_hash' => Hash::make(strtolower(explode(' ', $definition['full_name'])[0][0].explode(' ', $definition['full_name'])[1]).'123'),
                    'created_by' => $admin->user_id,
                    'is_active' => true,
                ]
            );

            if ($definition['level'] === 'staff') {
                $user->workflowStages()->sync(array_map(fn (string $key) => $stagesByName[$key]->stage_id, $definition['stages']));
            } else {
                $user->workflowStages()->sync([]);
            }

            $createdApprovers[] = $user;
        }

        // Skips anyone already verified — a re-run of this idempotent
        // seeder shouldn't re-send a link to an account that already clicked it.
        foreach (array_merge([$admin], $createdOriginators, $createdApprovers) as $seededUser) {
            if (! $seededUser->hasVerifiedEmail()) {
                $seededUser->sendEmailVerificationNotification();
            }
        }
    }
}
