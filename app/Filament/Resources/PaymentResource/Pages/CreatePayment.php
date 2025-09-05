<?php

declare(strict_types=1);

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use App\Models\Booking;
use App\Models\Job;
use Filament\Resources\Pages\CreateRecord;

class CreatePayment extends CreateRecord
{
    protected static string $resource = PaymentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // If booking wasn't chosen, try to find it by reference or via the job.
        if (empty($data['booking_id'])) {
            if (!empty($data['reference'])) {
                $data['booking_id'] = Booking::where('reference', $data['reference'])->value('id');
            }

            if (empty($data['booking_id']) && !empty($data['job_id'])) {
                $data['booking_id'] = Job::whereKey($data['job_id'])->value('booking_id');
            }
        }

        // OPTIONAL: If still empty, you can auto-create a Booking record:
        // if (empty($data['booking_id']) && !empty($data['reference'])) {
        //     $booking = Booking::firstOrCreate(['reference' => $data['reference']]);
        //     $data['booking_id'] = $booking->id;
        // }

        return $data;
    }
}
