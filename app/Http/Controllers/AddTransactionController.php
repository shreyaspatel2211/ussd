<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Customer;
use TCG\Voyager\Events\BreadDataDeleted;
use App\Models\Transaction;
use TCG\Voyager\Http\Controllers\VoyagerBaseController;
use Carbon\Carbon;
use App\Models\Company;
use TCG\Voyager\Facades\Voyager;
use TCG\Voyager\Events\BreadDataAdded;
use TCG\Voyager\Events\BreadDataUpdated;
use Illuminate\Support\Facades\Auth;

class AddTransactionController extends VoyagerBaseController
{
    public function store(Request $request)
    {
        if (empty($request->phone_number) || empty($request->amount) || empty($request->datetime) || empty($request->selected_plan_id)) {
            return redirect()->back()->with([
                'message'    => "Please fill up all details",
                'alert-type' => 'error',
            ]);
        }
        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Check permission
        $this->authorize('add', app($dataType->model_name));

        // Validate fields with ajax
        $val = $this->validateBread($request->all(), $dataType->addRows)->validate();
        $data = $this->insertUpdateData($request, $slug, $dataType->addRows, new $dataType->model_name());
        $data->status = "success";
        $data->save();
        event(new BreadDataAdded($dataType, $data));

        // Assuming 'customer_id' and 'amount' are fields in the transaction model
        $customer = Customer::where('phone_number',$data->phone_number)->first();
        
        $transactionDetails = Transaction::where('phone_number',$customer->phone_number)->where('status','success')->sum('amount');
        $latestTransaction = Transaction::where('phone_number',$customer->phone_number)->where('status','success')->latest('created_at')->first();
        if (!empty($latestTransaction)) {
            Customer::where('id',$customer->id)->update([
                'balance'=>$transactionDetails,
                'plan_id'=> $latestTransaction->selected_plan_id
            ]);
        }else{
            Customer::where('id',$customer->id)->update([
                'balance'=>$transactionDetails,
                'plan_id'=> null
            ]);
        }

        if (!$request->has('_tagging')) {
            if (auth()->user()->can('browse', $data)) {
                $redirect = redirect()->route("voyager.{$dataType->slug}.index");
            } else {
                $redirect = redirect()->back();
            }

            return $redirect->with([
                'message'    => __('voyager::generic.successfully_added_new')." {$dataType->getTranslatedAttribute('display_name_singular')}",
                'alert-type' => 'success',
            ]);
        } else {
            return response()->json(['success' => true, 'data' => $data]);
        }
    }

    public function update(Request $request, $id)
    {
        if (empty($request->phone_number) || empty($request->amount) || empty($request->datetime) || empty($request->selected_plan_id)) {
            return $redirect->with([
                'message'    => "Please fill up all details",
                'alert-type' => 'danger',
            ]);
        }

        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Compatibility with Model binding.
        $id = $id instanceof \Illuminate\Database\Eloquent\Model ? $id->{$id->getKeyName()} : $id;

        $model = app($dataType->model_name);
        $query = $model->query();
        if ($dataType->scope && $dataType->scope != '' && method_exists($model, 'scope'.ucfirst($dataType->scope))) {
            $query = $query->{$dataType->scope}();
        }
        if ($model && in_array(SoftDeletes::class, class_uses_recursive($model))) {
            $query = $query->withTrashed();
        }

        $data = $query->findOrFail($id);

        // Track original status, amount, and customer before update
        $originalStatus = $data->status;
        $originalAmount = $data->amount;
        $originalCustomerId = $data->customer_id;

        // Check permission
        $this->authorize('edit', $data);

        // Validate fields with ajax
        $val = $this->validateBread($request->all(), $dataType->editRows, $dataType->name, $id)->validate();

        // Get fields with images to remove before updating and make a copy of $data
        $to_remove = $dataType->editRows->where('type', 'image')
            ->filter(function ($item, $key) use ($request) {
                return $request->hasFile($item->field);
            });
        $original_data = clone($data);

        // Update transaction data
        $this->insertUpdateData($request, $slug, $dataType->editRows, $data);

        // Delete Images
        $this->deleteBreadImages($original_data, $to_remove);

        $customer = Customer::where('phone_number',$data->phone_number)->first();
        
        $transactionDetails = Transaction::where('phone_number',$customer->phone_number)->where('status','success')->sum('amount');
        $latestTransaction = Transaction::where('phone_number',$customer->phone_number)->where('status','success')->latest('created_at')->first();
        if (!empty($latestTransaction)) {
            Customer::where('id',$customer->id)->update([
                'balance'=>$transactionDetails,
                'plan_id'=> $latestTransaction->selected_plan_id
            ]);
        }else{
            Customer::where('id',$customer->id)->update([
                'balance'=>$transactionDetails,
                'plan_id'=> null
            ]);
        }

        event(new BreadDataUpdated($dataType, $data));

        if (auth()->user()->can('browse', app($dataType->model_name))) {
            $redirect = redirect()->route("voyager.{$dataType->slug}.index");
        } else {
            $redirect = redirect()->back();
        }

        return $redirect->with([
            'message'    => __('voyager::generic.successfully_updated')." {$dataType->getTranslatedAttribute('display_name_singular')}",
            'alert-type' => 'success',
        ]);
    }

    public function userTransaction($id){
        return view('vendor.voyager.transactions.user-transaction',compact('id'));
    }
    public function destroy(Request $request, $id)
    {
        $slug = $this->getSlug($request);

        $dataType = Voyager::model('DataType')->where('slug', '=', $slug)->first();

        // Init array of IDs
        $ids = [];
        if (empty($id)) {
            // Bulk delete, get IDs from POST
            $ids = explode(',', $request->ids);
        } else {
            // Single item delete, get ID from URL
            $ids[] = $id;
        }

        $affected = 0;
        
        foreach ($ids as $id) {
            $data = call_user_func([$dataType->model_name, 'findOrFail'], $id);

            // Check permission
            $this->authorize('delete', $data);

            $model = app($dataType->model_name);
            if (!($model && in_array(SoftDeletes::class, class_uses_recursive($model)))) {
                $this->cleanup($dataType, $data);
            }

            $res = $data->delete();

            if ($res) {
                $affected++;

                event(new BreadDataDeleted($dataType, $data));
            }
        }
        if (!empty($data)) {
            $customer = Customer::where('phone_number',$data->phone_number)->first();

            $transactionDetails = Transaction::where('phone_number',$customer->phone_number)->where('status','success')->sum('amount');
            $latestTransaction = Transaction::where('phone_number',$customer->phone_number)->where('status','success')->latest('created_at')->first();
            if (!empty($latestTransaction)) {
                Customer::where('id',$customer->id)->update([
                    'balance'=>$transactionDetails,
                    'plan_id'=> $latestTransaction->selected_plan_id
                ]);
            }else{
                Customer::where('id',$customer->id)->update([
                    'balance'=>$transactionDetails,
                    'plan_id'=> null
                ]);
            }
        }

        $displayName = $affected > 1 ? $dataType->getTranslatedAttribute('display_name_plural') : $dataType->getTranslatedAttribute('display_name_singular');

        $data = $affected
            ? [
                'message'    => __('voyager::generic.successfully_deleted')." {$displayName}",
                'alert-type' => 'success',
            ]
            : [
                'message'    => __('voyager::generic.error_deleting')." {$displayName}",
                'alert-type' => 'error',
            ];

        return redirect()->route("voyager.{$dataType->slug}.index")->with($data);
    }
}
