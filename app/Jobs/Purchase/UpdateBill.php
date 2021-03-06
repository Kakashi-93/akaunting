<?php

namespace App\Jobs\Purchase;

use App\Abstracts\Job;
use App\Events\Purchase\BillUpdated;
use App\Events\Purchase\BillUpdating;
use App\Jobs\Purchase\CreateBillItemsAndTotals;
use App\Models\Purchase\Bill;
use App\Traits\Relationships;

class UpdateBill extends Job
{
    use Relationships;

    protected $bill;

    protected $request;

    /**
     * Create a new job instance.
     *
     * @param  $request
     */
    public function __construct($bill, $request)
    {
        $this->bill = $bill;
        $this->request = $this->getRequestInstance($request);
    }

    /**
     * Execute the job.
     *
     * @return Bill
     */
    public function handle()
    {
        if (empty($this->request['amount'])) {
            $this->request['amount'] = 0;
        }

        event(new BillUpdating($this->bill, $this->request));

        \DB::transaction(function () {
            // Upload attachment
            if ($this->request->file('attachment')) {
                $media = $this->getMedia($this->request->file('attachment'), 'bills');

                $this->bill->attachMedia($media, 'attachment');
            }

            $this->deleteRelationships($this->bill, ['items', 'item_taxes', 'totals']);

            $this->dispatch(new CreateBillItemsAndTotals($this->bill, $this->request));

            $bill_paid = $this->bill->paid;

            unset($this->bill->reconciled);

            if (($bill_paid) && $this->request['amount'] > $bill_paid) {
                $this->request['status'] = 'partial';
            }

            $this->bill->update($this->request->input());

            $this->bill->updateRecurring();
        });

        event(new BillUpdated($this->bill));

        return $this->bill;
    }
}
