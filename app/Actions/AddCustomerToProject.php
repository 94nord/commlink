<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;

class AddCustomerToProject
{
    public function handle(Project $project, Customer $customer, string $address): WorkOrder
    {
        return DB::transaction(function () use ($project, $customer, $address) {
            $corporation = $project->corporation;

            $workOrder = WorkOrder::create([
                'corporation_id' => $corporation->id,
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'address' => $address,
            ]);

            EventRecorder::record($corporation, 'work_order.created', subject: $workOrder);

            return $workOrder;
        });
    }
}
