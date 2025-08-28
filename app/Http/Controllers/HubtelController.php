<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Subscription;
use App\Models\CallbackRequest;
use App\Models\Plan;
use App\Models\Loan;
use App\Models\LoanRequest;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\WithdrawlRequest;
use App\Models\UssdMenu;
use Illuminate\Support\Str;
use App\Models\Product;
use App\Models\LoanProduct;

class HubtelController extends Controller
{
    public function hubtelUSSD(Request $request, $company_id){
        $caseType = null;
        if($request->Type == 'Response'){
            if ($request->Sequence == 2) {
                switch ($request->Message) {
                    case '1':
                        $caseType = 'register';
                        break;
                    case '9':
                        $caseType = 'checkbalance';
                        break;
                    case '2':
                        $caseType = 'dues';
                        break;
                    case '10':
                        $caseType = 'contact';
                        break;
                    case '3':
                        $caseType = 'loan';
                        break;
                    case '4':
                        $caseType = 'loanRepayment';
                        break;
                    case '8':
                        $caseType = 'withdrawl';
                        break;
                    case '5':
                        $caseType = 'susu_savings';
                        break;
                    case '6':
                        $caseType = 'product';
                        break;
                    case '7':
                        $caseType = 'payFees';
                        break;
                }
            } else {
                $lastsessionData = Session::where('session_id', $request->SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                $caseType = $lastsessionData->casetype;
            }
        }
        $newSessionData = Session::create([
            'request_json' => json_encode($request->all()),
            'mobile' => $request->Mobile,
            'session_id' => $request->SessionId,
            'sequence' => $request->Sequence,
            'message' => $request->Message,
            'casetype' => $caseType,
            'operator' => $request->Operator
        ]);

        $responseTypeData = $this->handleType($request->Type,$request->Message,$request->Sequence,$request->SessionId,$caseType,$request->ServiceCode, $company_id, $request->Mobile, $request->Operator);
        if ($caseType == "register" && isset(($responseTypeData['internal_number'])) && !empty($responseTypeData['internal_number'])) {
            Session::where('id',$newSessionData->id)->update(['internal_number'=> $responseTypeData['internal_number']]);
        }
        $lastSelectedData = Session::where('session_id', $request->SessionId)
                          ->whereNotNull('selected_plan_id')
                          ->whereNotNull('payment_system')
                          ->first();

        $selected_plan_id = $lastSelectedData ? $lastSelectedData->selected_plan_id : null; 
        $payment_system = $lastSelectedData ? $lastSelectedData->payment_system : null; 

        if($payment_system != "" && $selected_plan_id != ""){

            $planPrice = Plan::where('plan_id', $selected_plan_id)->first();
                if($payment_system == 1){
                    $column = "daily";
                } else if($payment_system == 2){
                    $column = "weekly";
                } else {
                    $column = "monthly";
                }
            $priceValue = $planPrice->$column;
            $plan_name = $planPrice->name;
            // return $this->responseBuilderForPayment(
            //     $request->SessionId, 
            //     'AddToCart',                         
            //     $responseTypeData['message'], 
            //     $plan_name,
            //     1,
            //     $priceValue,
            //     $responseTypeData['label'],                      
            //     $responseTypeData['data_type'],                             
            //     'text'                               
            // );
            return $this->responseBuilder(
                $request->SessionId, 
                'response',                         
                $responseTypeData['message'], 
                $responseTypeData['label'],                      
                $request->ClientState ? $request->ClientState:"",                               
                $responseTypeData['data_type'],                             
                'text'                               
            );
        } else {
            return $this->responseBuilder(
                $request->SessionId, 
                'response',                         
                $responseTypeData['message'], 
                $responseTypeData['label'],                      
                $request->ClientState ? $request->ClientState:"",                               
                $responseTypeData['data_type'],                             
                'text'                               
            );
        }
    }

    public function hubtelUSSDCallback(Request $request, $company_id)
    {
        CallbackRequest::create(['request' => json_encode($request->all())]);
        Log::info("Callback request{$request}");
        
        if(isset($request->Data['RecurringInvoiceId']) && !empty($request->Data['RecurringInvoiceId'])){
            
            $lastTransaction = Transaction::where('recurring_invoice_id',$request->Data['RecurringInvoiceId'])->orderBy('created_at','DESC')->first();
            Log::info("Last Transaction {$lastTransaction}");
            if ($request->ResponseCode == "0000") {
                
                if(isset($lastTransaction->selected_plan_id) && !empty($lastTransaction->selected_plan_id)){
                    $plan_id = $lastTransaction->selected_plan_id;
                }
                if(isset($lastTransaction->phone_number) && !empty($lastTransaction->phone_number)){
                    $phone_number = $lastTransaction->phone_number;
                    if (strpos($phone_number, '233') === 0) {
                        $phone_number = '0' . substr($phone_number, 3);
                    }
                }

                $number = $request->Data['CustomerMobileNumber'];
                $formattedNumber = preg_replace('/^233/', '0', $number);
                Transaction::where('recurring_invoice_id',$request->Data['RecurringInvoiceId'])->where('status','authenticated')->delete();
                if(isset($plan_id) && !empty($plan_id)){
                    $customer_id = Customer::where('phone_number',$phone_number)->first();

                    $product = Product::where('id', $plan_id)->first();  
                    Transaction::create(['customer_id' => $customer_id->id,'name'=> $product->name,'description'=>$request->Data['Description'],'recurring_invoice_id'=>$request->Data['RecurringInvoiceId'],'amount'=>$request->Data['RecurringAmount'],'selected_plan_id'=>$plan_id,'status'=>'success','phone_number'=>$lastTransaction->phone_number,'datetime'=>Carbon::now(),'company_id'=>$company_id, 'client_reference'=>$request->Data['ClientReference']]);
                    Customer::where('phone_number',$phone_number)->update([
                        'plan_id' => $plan_id
                    ]);
                } else if (isset($phone_number) && !empty($phone_number)){
                    $customer_id = Customer::where('phone_number',$phone_number)->first();

                    Transaction::create(['customer_id' => $customer_id->id,'name'=>$request->Data['Description'],'description'=>$request->Data['Description'],'recurring_invoice_id'=>$request->Data['RecurringInvoiceId'],'amount'=>$request->Data['RecurringAmount'],'status'=>'success','phone_number'=>$lastTransaction->phone_number,'datetime'=>Carbon::now(),'company_id'=>$company_id, 'client_reference'=>$request->Data['ClientReference']]);
                } else {
                    Transaction::create(['customer_id' => 1,'name'=>$request->Data['Description'],'description'=>$request->Data['Description'],'recurring_invoice_id'=>$request->Data['RecurringInvoiceId'],'amount'=>$request->Data['RecurringAmount'],'status'=>'success','datetime'=>Carbon::now(),'phone_number'=>$formattedNumber,'company_id'=>$company_id, 'client_reference'=>$request->Data['ClientReference']]);
                }
                Log::info("Transaction Successfull for recurring invoice id " . $request->Data['RecurringInvoiceId']);

            } else {
                $customer_id = Customer::where('phone_number',$lastTransaction->phone_number)->first();
                Transaction::create(['customer_id' => $customer_id->id,'name'=>$request->Data['Description'],'description'=>$request->Data['Description'],'recurring_invoice_id'=>$request->Data['RecurringInvoiceId'],'amount'=>$request->Data['RecurringAmount'],'status'=>'failed','phone_number'=>$lastTransaction->phone_number,'datetime'=>Carbon::now(),'company_id'=>$company_id, 'client_reference'=>$request->Data['ClientReference']]);

                // Transaction::where('recurring_invoice_id', $request->Data['RecurringInvoiceId'])
                //             ->update(['status' => 'failed']);
                Log::info("Transaction Failed for recurring invoice id " . $request->Data['RecurringInvoiceId']);

            }
            
        } else {
            $TransactionId = $request->Data['TransactionId'];
            $lastTransaction = Transaction::where('transaction_id',$TransactionId)->orderBy('created_at','DESC')->first();
            Log::info("Last Transaction {$lastTransaction}");

            if ($request->ResponseCode == "0000") {
                if(isset($lastTransaction->selected_plan_id) && !empty($lastTransaction->selected_plan_id)){
                    $plan_id = $lastTransaction->selected_plan_id;
                }
                $phone_number = $lastTransaction->phone_number;

                $customer_id = Customer::where('phone_number',$phone_number)->first();
                if (strpos($phone_number, '233') === 0) {
                    $phone_number = '0' . substr($phone_number, 3);
                }          $customer_id = Customer::where('phone_number', $phone_number)->first();

                // Transaction::where('transaction_id',$TransactionId)->where('status','pending')->delete();
                if(isset($plan_id) && !empty($plan_id)){
                    Transaction::where('transaction_id', $TransactionId)
                            ->update(['status' => 'success']);
                    // Transaction::create(['customer_id' => $customer_id->id,'name'=>$request->Data['Description'],'description'=>$request->Data['Description'],'transaction_id'=>$request->Data['TransactionId'],'amount'=>$request->Data['Amount'],'selected_plan_id'=>$plan_id,'status'=>'success','phone_number'=>$lastTransaction->phone_number,'datetime'=>Carbon::now(),'company_id'=>$company_id,'charges'=>$request->Data['charges'],'amount_charged'=>$request->Data['AmountCharged']]);
                    Customer::where('phone_number',$phone_number)->update([
                        // 'packages_start_index' => 0,
                        'plan_id' => $plan_id
                    ]);
                } else {
                    Transaction::where('transaction_id', $TransactionId)
                            ->update(['status' => 'success']);
                    // Transaction::create(['customer_id' => $customer_id->id,'name'=>$request->Data['Description'],'description'=>$request->Data['Description'],'transaction_id'=>$request->Data['TransactionId'],'amount'=>$request->Data['Amount'],'status'=>'success','phone_number'=>$lastTransaction->phone_number,'datetime'=>Carbon::now(),'company_id'=>$company_id,'charges'=>$request->Data['Charges'],'amount_charged'=>$request->Data['AmountCharged']]);
                }
                Log::info("Transaction Successfull for transaction id:" . $request->Data['TransactionId']);
            } else {
                Transaction::where('transaction_id', $TransactionId)
                            ->update(['status' => 'failed']);
                Log::info("Transaction Failed for transaction id" . $TransactionId);
            }
        }

    }

    public function handleType($type,$inputmessage,$sequence,$SessionId,$caseType,$ServiceCode, $company_id, $Mobile, $Operator){
        

        $message = "";
        $label ="";
        $dataType = "";
        if (Str::startsWith($Mobile, '233')) {
            $Mobile = '0' . substr($Mobile, 3);
        }
        $sessionData = Session::where('session_id', $SessionId)
                    ->where('sequence', 2)
                    ->whereNotNull('message')
                    ->whereNotNull('request_json')
                    ->whereNull('response_json')
                    ->orderBy('id', 'desc') 
                    ->first();

        $company = Company::where('company_id', $company_id)->first();
        
        $USSDIds = DB::table('company_ussd_menu')->where('company_id', $company->id)->pluck('ussd_menu_id');
        $ussd_menus = UssdMenu::whereIn('id', $USSDIds)->orderBy('menu_order')->get();
        $phone_number_check = $company->phone_number_check ?? 'no';
        switch ($type) {
            case 'Initiation':
                $message = "Welcome to " . $company->name . ".\nWhat do you want to do:\n";
                foreach ($ussd_menus as $menu) {
                    $message .= $menu->menu_order . ". " . $menu->name . "\n";
                }
                // $message = "Welcome to ".$company->name.".\nWhat do you want to do:\n1. Register\n2. Check balance\n3. Make Payment\n4. Contact Us\n5. Loan\n6. Loan Repayment\n7. Withdrawl\n8. Susu-Savings\n9. Add Package";
                $label = "Welcome";
                $dataType = "input";
                break;
            case 'Response':
                switch ($caseType) {
                    case 'register':
                        if ($phone_number_check == 'yes') {
                            $RegisterScreen = $this->handleRegisterScreen($SessionId, $sequence, $company_id, $inputmessage, $caseType, $ServiceCode, $Mobile, $Operator);
                        } else {
                            $RegisterScreen = $this->handleRegisterScreenNoPhone($SessionId, $sequence, $company_id, $inputmessage, $caseType, $ServiceCode, $Mobile, $Operator);
                        }
                        $message = $RegisterScreen['message'];
                        $label = $RegisterScreen['label'];
                        $dataType = $RegisterScreen['data_type'];
                        $internal_number = !empty($RegisterScreen['internal_number']) ? $RegisterScreen['internal_number']:0;
                        break;
                    case 'checkbalance':
                        if ($phone_number_check == 'yes') {
                            $checkBalanceScreen = $this->handleCheckBalanceScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $checkBalanceScreen = $this->handleCheckBalanceScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $checkBalanceScreen['message'];
                        $label = $checkBalanceScreen['label'];
                        $dataType = $checkBalanceScreen['data_type'];
                        break;
                    case 'dues':
                        if ($phone_number_check == 'yes') {
                            $DuesScreen = $this->handleDuesScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $DuesScreen = $this->handleDuesScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $DuesScreen['message'];
                        $label = $DuesScreen['label'];
                        $dataType = $DuesScreen['data_type'];
                        break;
                    case 'contact':
                        if ($phone_number_check == 'yes') {
                            $ContactScreen = $this->handleContactScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $ContactScreen = $this->handleContactScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $ContactScreen['message'];
                        $label = $ContactScreen['label'];
                        $dataType = $ContactScreen['data_type'];
                        break;
                    case 'loan':
                        if ($phone_number_check == 'yes') {
                            $LoanScreen = $this->handleLoanScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $LoanScreen = $this->handleLoanScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $LoanScreen['message'];
                        $label = $LoanScreen['label'];
                        $dataType = $LoanScreen['data_type'];
                        break;
                    case 'loanRepayment':
                        if ($phone_number_check == 'yes') {
                            $LoanRepaymentScreen = $this->handleLoanRepaymentScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $LoanRepaymentScreen = $this->handleLoanRepaymentScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $LoanRepaymentScreen['message'];
                        $label = $LoanRepaymentScreen['label'];
                        $dataType = $LoanRepaymentScreen['data_type'];
                        break;
                    case 'withdrawl':
                        if ($phone_number_check == 'yes') {
                            $WithdrawlScreen = $this->handleWithdrawlScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $WithdrawlScreen = $this->handleWithdrawlScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $WithdrawlScreen['message'];
                        $label = $WithdrawlScreen['label'];
                        $dataType = $WithdrawlScreen['data_type'];
                        break;
                    case 'susu_savings':
                        if ($phone_number_check == 'yes') {
                            $SusuSavingsScreen = $this->handleSusuSavingsScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $SusuSavingsScreen = $this->handleSusuSavingsScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $SusuSavingsScreen['message'];
                        $label = $SusuSavingsScreen['label'];
                        $dataType = $SusuSavingsScreen['data_type'];
                        break;
                    case 'addPackage':
                        if ($phone_number_check == 'yes') {
                            $AddPackageScreen = $this->handleAddPackageScreen($SessionId,$sequence,$company_id,$Mobile,$phone_number_check);
                        } else {
                            $AddPackageScreen = $this->handleAddPackageScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$phone_number_check);
                        }
                        $message = $AddPackageScreen['message'];
                        $label = $AddPackageScreen['label'];
                        $dataType = $AddPackageScreen['data_type'];
                        break;
                    case 'payFees':
                        if ($phone_number_check == 'yes') {
                            $PayFeesScreen = $this->handlePayFeesScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $PayFeesScreen = $this->handlePayFeesScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $PayFeesScreen['message'];
                        $label = $PayFeesScreen['label'];
                        $dataType = $PayFeesScreen['data_type'];
                        break;
                    case 'product':
                        if ($phone_number_check == 'yes') {
                            $ProductScreen = $this->handleProductScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        } else {
                            $ProductScreen = $this->handleProductScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check);
                        }
                        $message = $ProductScreen['message'];
                        $label = $ProductScreen['label'];
                        $dataType = $ProductScreen['data_type'];
                        break; 

                }
                break;
            
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType,
            "internal_number" => !empty($internal_number) ? $internal_number:60
        ];
    }

    /**
     * Build a structured response.
     *
     * @param string $sessionId
     * @param string $type
     * @param string $message
     * @param string $label
     * @param string $clientState
     * @param string $dataType
     * @param string $fieldType
     * @return JsonResponse
     */
    public function responseBuilder(
        string $sessionId,
        string $type,
        string $message,
        string $label,
        string $clientState="",
        string $dataType,
        string $fieldType
    ): JsonResponse {
        // Structure the response array
        $response = [
            'SessionId'   => $sessionId,
            'Type'        => ($dataType == "display") ? "release":$type,
            'Message'     => $message,
            'Label'       => $label,
            'ClientState' => $clientState,
            'DataType'    => $dataType,
            'FieldType'   => ($dataType == "display") ? "":$fieldType,
        ];

        Session::create([
            'response_json' => json_encode($response),
            'session_id' => $sessionId,
            'message' => $message
        ]);


        // Return the response as a JSON
        return response()->json($response);
    }

    public function responseBuilderForPayment(
        string $sessionId,
        string $type,
        string $message,
        string $plan_name,
        string $quantity,
        string $priceValue,
        string $label,
        string $dataType,
        string $fieldType
    ): JsonResponse {
        // Structure the response array
        $response = [
            'SessionId'   => $sessionId,
            'Type'        => $type,
            'Message'     => $message,
            'Item' => [
                'ItemName' => $plan_name, 
                'Qty' => $quantity,                 
                'Price' => 0.001    // $priceValue replace this variable to 1 when project goes to live    
            ],
            'Label'       => $label,
            'DataType'    => $dataType,
            'FieldType'   => $fieldType,
        ];

        Session::create([
            'response_json' => json_encode($response),
            'session_id' => $sessionId,
            'message' => $message
        ]);


        // Return the response as a JSON
        return response()->json($response);
    }

    public function handleRegisterScreen($SessionId,$sequence,$company_id,$inputmessage,$caseType,$ServiceCode,$Mobile,$Operator){
        $message = "";
        $label ="";
        $dataType = "";
        $internal_number=0;
        switch ($sequence) {
            case '2':
                    if ($Mobile) {
                    // Assuming phone number is stored in message or request_json
                    $phone_number = $Mobile; // or decode request_json if needed
                    // Check if phone number already exists in Customer model
                    $exists = Customer::where('phone_number', $phone_number)->exists();
                
                    if ($exists) {
                        return [
                            "message" => 'Phone Number is already exist. Please use another number.',
                            "label" => 'PhoneNumber',
                            "data_type" => 'display',
                            "internal_number" => $Mobile
                        ];
                    }
                }
                $message = "Enter your First Name";
                $label = "FirstName";
                $dataType = "text";
                $internal_number = 2;
                break;
            case '3':
                $message = "Enter your Last Name";
                $label = "LastName";
                $dataType = "text";
                $internal_number = 3;
                break;
            // case '4':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     $internal_number = 4;
            //     break;
            case '4':
                // $phone_number_for_register = Session::where('session_id', $SessionId)
                //             ->whereNotNull('message')
                //             ->whereNotNull('request_json')
                //             ->whereNull('response_json')
                //             ->orderBy('id', 'desc')
                //             ->first();

                if ($Mobile) {
                    // Assuming phone number is stored in message or request_json
                    $phone_number = $Mobile; // or decode request_json if needed
                    // Check if phone number already exists in Customer model
                    $exists = Customer::where('phone_number', $phone_number)->exists();
                
                    if ($exists) {
                        return [
                            "message" => 'Phone Number is already exist. Please use another number.',
                            "label" => 'PhoneNumber',
                            "data_type" => 'display',
                            "internal_number" => $Mobile
                        ];
                    }
                }

                $message = "Enter 4 digits pin";
                $label = "PIN";
                $dataType = "text";
                $internal_number = 5;
                break;
            case '5':
                $firstName = Session::where('session_id', $SessionId)->where('internal_number',2)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->skip(2)
                            ->first();
                    $lastName = Session::where('session_id', $SessionId)->where('internal_number',3)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->skip(1) 
                            ->first();
                    // $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                    //         ->whereNotNull('message')
                    //         ->whereNotNull('request_json')
                    //         ->whereNull('response_json')
                    //         ->orderBy('id', 'desc')
                    //         ->first();
                    $phoneNumber = $Mobile;
                    $PIN = Session::where('session_id', $SessionId)->where('sequence',4)->where('casetype','register')
                                ->orderBy('id', 'desc') 
                                ->first();

                    if($Operator== "vodafone") {
                        $Operator = "vodafone_gh_rec";
                    } else if($Operator== "mtn") {
                        $Operator = "mtn_gh_rec";
                    } else if($Operator== "airtel") {
                        $Operator = "airtel_gh_rec";
                    }
                    $companyID_for_create = Company::where('company_id', $company_id)->first();
                    Customer::create([
                        'name' => $firstName->message . " " . $lastName->message,
                        'phone_number' => $Mobile,
                        'pin' => $PIN->message,
                        'operator_channel'=> $Operator,
                        'company_id' => $companyID_for_create->id
                    ]);
                    // $type = 'Initiation';
                    // return $this->handleType($type, $inputmessage, $sequence, $SessionId, $caseType, $ServiceCode, $company_id);
                    $message = "You are succesfully registered";
                    $label = "Registered";
                    $dataType = "display";
                    $internal_number = 7;
                break;
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType,
            "internal_number"=> $internal_number
        ];
    }

    public function handleRegisterScreenNoPhone($SessionId,$sequence,$company_id,$inputmessage,$caseType,$ServiceCode,$Mobile,$Operator){
        $message = "";
        $label ="";
        $dataType = "";
        $internal_number=0;
        switch ($sequence) {
            case '2':
                $message = "Enter your First Name";
                $label = "FirstName";
                $dataType = "text";
                $internal_number = 2;
                break;
            case '3':
                $message = "Enter your Last Name";
                $label = "LastName";
                $dataType = "text";
                $internal_number = 3;
                break;
            case '4':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                $internal_number = 4;
                break;
            case '5':
                $phone_number_for_register = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();

                if ($phone_number_for_register) {
                    // Assuming phone number is stored in message or request_json
                    $phone_number = $phone_number_for_register->message; // or decode request_json if needed
                    // Check if phone number already exists in Customer model
                    $exists = Customer::where('phone_number', $phone_number)->exists();
                
                    if ($exists) {
                        return [
                            "message" => 'Phone Number is already exist. Please use another number.',
                            "label" => 'PhoneNumber',
                            "data_type" => 'display',
                            "internal_number" => $phone_number_for_register->internal_number
                        ];
                    }
                }

                $message = "Enter Provider\n1. Vodafone\n2.MTN";
                $label = "Provider";
                $dataType = "text";
                $internal_number = 5;
                break;
            case '6':
                // $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                // ->whereNotNull('message')
                // ->whereNotNull('request_json')
                // ->whereNull('response_json')
                // ->orderBy('id', 'desc')
                // ->first();
                // $oldCustomerInfo = Customer::where('phone_number', $phoneNumber->message)->where('reset_pin',1)->first();
                // if (!empty($oldCustomerInfo)) {
                //     $message = "As you are requested to reset your account Please enter the otp which comes up in your phone number.";
                //     $label = "PIN";
                //     $dataType = "text";
                // }else{
                    $message = "Enter 4 digits pin";
                    $label = "PIN";
                    $dataType = "text";
                    $internal_number = 6;
                // }
                break;
            case '7':
                $firstName = Session::where('session_id', $SessionId)->where('internal_number',3)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();
                    $lastName = Session::where('session_id', $SessionId)->where('internal_number',4)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->first();
                    $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();
                    $provider = Session::where('session_id', $SessionId)->where('internal_number',6)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc')
                                ->first();
                    $PIN = Session::where('session_id', $SessionId)->where('sequence',7)->where('casetype','register')
                                ->orderBy('id', 'desc') 
                                ->first();

                    $operator_value = $provider->message;
                    if($operator_value == "1"){
                        $Operator = "vodafone_gh_rec";
                    } else {
                        $Operator = "mtn_gh_rec";
                    }
                    $companyID_for_create = Company::where('company_id', $company_id)->first();
                    Customer::create([
                        'name' => $firstName->message . " " . $lastName->message,
                        'phone_number' => $phoneNumber->message,
                        'pin' => $PIN->message,
                        'operator_channel'=> $Operator,
                        'company_id' => $companyID_for_create->id
                    ]);
                    // $type = 'Initiation';
                    // return $this->handleType($type, $inputmessage, $sequence, $SessionId, $caseType, $ServiceCode, $company_id);
                    $message = "You are succesfully registered";
                    $label = "Registered";
                    $dataType = "display";
                    $internal_number = 7;
                break;
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType,
            "internal_number"=> $internal_number
        ];
    }

    public function handleProductScreen($SessionId,$sequence,$company_id,$Mobile,$Operator){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        $internal_number=0;
        switch ($sequence) {
            // case '2':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     $internal_number = 2;
            //     break;
            // case '3':
            //     $message = "Enter Provider\n1. Vodafone\n2.MTN";
            //     $label = "Provider";
            //     $dataType = "text";
            //     $internal_number = 3;
            //     break;
            case '2':
                // $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                //             ->whereNotNull('message')
                //             ->whereNotNull('request_json')
                //             ->whereNull('response_json')
                //             ->orderBy('id', 'desc')
                //             ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $Mobile)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $Mobile){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                
                // $oldCustomerInfo = Customer::where('phone_number', $phoneNumber->message)->where('reset_pin',1)->first();
                // if (!empty($oldCustomerInfo)) {
                //     $message = "As you are requested to reset your account Please enter the otp which comes up in your phone number.";
                //     $label = "PIN";
                //     $dataType = "text";
                // }else{
                    $message = "Enter 4 digits pin";
                    $label = "PIN";
                    $dataType = "text";
                    $internal_number = 4;
                // }
                break;
            case '3':
                $company = Company::where('company_id', $company_id)->first();
                $plans = Product::where('company_id', $company->id)
                     ->get();
                
                $packages = "Choose your plan:";
                foreach ($plans as $plan) {
                    $packages .= "\n" . $plan->id . ". " . $plan->name;
                }

                $message = $packages;
                $label = "Plan";
                $dataType = "text";
                $internal_number = 5;
                break;
            case '4':
                $message = "Select Payment Plan: \n1.Subscribe\n2.Topup";
                $label = "PIN";
                $dataType = "text";
                $internal_number = 6;
                break;
            case '5';
                $payment = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                if($payment->message == '2'){
                    $message = "Enter the Amount";
                    $label = "Amount";
                    $dataType = "text";
                    $internal_number = 7;
                } else {
                    $message = "Select Payment Plan: \n1.Daily\n2.Weekly\n3.Monthly";
                    $label = "PaymentPlan";
                    $dataType = "text";
                    $internal_number = 7;
                }
                break;
            case '6':
                $payment = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                if($payment->message == '2'){
                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(4)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(4)
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                                
                    $Select_Plan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                                
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $product_name = Product::where('id', $Select_Plan->message)->first();

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        "CustomerMsisdn" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'Product',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $Mobile,
                    ]);

                    $customer_id = Customer::where('phone_number',$Mobile)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'name'=> $product_name->name,
                        'session_id' => $SessionId,
                        'phone_number'=> $Mobile,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'selected_plan_id' => $Select_Plan->message
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                } else {
                    $PaymentProduct = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();

                    $product = Product::where('id', $PaymentProduct->message)->first();

                    if($product->amount_type == 'Dynamic'){
                        $message = "Enter The Amount";
                        $label = "Amount";
                        $dataType = "text";
                    } else {
                        // $phoneNumber = Session::where('session_id', $SessionId)
                        //             ->whereNotNull('message')
                        //             ->whereNotNull('request_json')
                        //             ->whereNull('response_json')
                        //             ->orderBy('id', 'desc') 
                        //             ->skip(4)
                        //             ->first();

                        $customer = Customer::where('phone_number', $Mobile)->first();
                        
                        // $OperatorNumber = Session::where('session_id', $SessionId)
                        //             ->whereNotNull('message')
                        //             ->whereNotNull('request_json')
                        //             ->whereNull('response_json')
                        //             ->orderBy('id', 'desc') 
                        //             ->skip(4)
                        //             ->first();
                        
                        $Select_Plan = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(2)
                                    ->first();

                        $product_name = Product::where('id', $Select_Plan->message)->first();
                                    
                        if($Operator == 'vodafone'){
                            $Operator = 'vodafone_gh_rec';
                        } else {
                            $Operator = 'mtn_gh_rec';
                        }

                        $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                        if(empty($otpVerifySubmit)){
                            // handle payment
                            $lastsessionData = Session::where('session_id', $SessionId)
                                        ->whereNotNull('message')
                                        ->whereNotNull('request_json')
                                        ->whereNull('response_json')
                                        ->orderBy('id', 'desc')
                                        ->first();
                            $payment_system = $lastsessionData->message;
                            if($payment_system == 1){
                                $Pay_Role = "DAILY";
                            } else if($payment_system == 2) {
                                $Pay_Role = "WEEKLY";
                            } else {
                                $Pay_Role = "MONTHLY";
                            }
                            Session::create([
                                'session_id' => $SessionId,
                                'payment_system' => $payment_system,
                            ]);
                            
                            $company_cred = Company::where('company_id', $company_id)->first();
                            $api_id = $company_cred->api_id;
                            $pos_sales_id = $company_cred->pos_sales_id;
                            $api_key = $company_cred->api_key;

                            Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                            $token = base64_encode($api_id.":".$api_key);
                        
                            $response = Http::withHeaders([
                                'Authorization' => "Basic {$token}",
                                'Content-Type' => 'application/json'
                            ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                                "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                                "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                                "description" => $product_name->name,
                                "startTime" => now()->addMinutes(5)->format('H:i'),
                                "paymentInterval" => $Pay_Role,
                                "customerMobileNumber" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                                "paymentOption" => "MobileMoney",
                                "channel" => $Operator,
                                "customerName" => $customer->name,
                                "recurringAmount" => $product_name->cost,
                                "totalAmount" => $product_name->cost,
                                "initialAmount" => $product_name->cost,
                                "currency" => "GHS",
                                "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                            ]);

                            Log::info("Response Status: " . $response->status());
                            Log::info("response", ['body' => $response->getBody()->getContents()]);

                            $company_otp = Company::where('company_id', $company_id)->first();

                            $data = json_decode($response, true);
                            if((!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix'])) && $company_otp->otp_check == no){
                                $message = "Please check sms for status of transaction.";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            }
                            $requestId = $data['data']['requestId'];
                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                            $otpPrefix = $data['data']['otpPrefix'];

                            Session::create([
                                'request_id' => $requestId,
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'otp_prefix' => $otpPrefix,
                            ]);

                            $customer_id = Customer::where('phone_number',$Mobile)->first();

                            Transaction::create([ 
                                'customer_id' => $customer_id->id,
                                'name'=> $product_name->name,
                                'request_id' => $requestId,
                                'session_id' => $SessionId,
                                'phone_number'=> $Mobile,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'otpPrefix' => $otpPrefix,
                                'status' => 'pending',
                                'amount' => $product_name->cost,
                                'company_id' => $company_id,
                                'description' => 'Product',
                                'selected_plan_id' => $Select_Plan->message
                            ]);
                            
                            Log::info("response:{$response}");
                            
                            $message = "Enter OTP";
                            $label = "PaymentOTP";
                            $dataType = "text";

                        }
                    }
                }
                break;
            case '7':

                $PaymentProduct = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(3)
                                ->first();

                $product = Product::where('id', $PaymentProduct->message)->first();

                if($product->amount_type == 'Dynamic'){
                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //                 ->whereNotNull('message')
                    //                 ->whereNotNull('request_json')
                    //                 ->whereNull('response_json')
                    //                 ->orderBy('id', 'desc') 
                    //                 ->skip(4)
                    //                 ->first();

                        $customer = Customer::where('phone_number', $Mobile)->first();
                        
                        // $OperatorNumber = Session::where('session_id', $SessionId)
                        //             ->whereNotNull('message')
                        //             ->whereNotNull('request_json')
                        //             ->whereNull('response_json')
                        //             ->orderBy('id', 'desc') 
                        //             ->skip(5)
                        //             ->first();
                        
                        $Select_Plan = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(2)
                                    ->first();

                        $Entered_amount = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(1)
                                    ->first();

                        $product_name = Product::where('id', $Select_Plan->message)->first();
                                    
                        if($Operator == 'vodafone'){
                            $Operator = 'vodafone_gh_rec';
                        } else {
                            $Operator = 'mtn_gh_rec';
                        }

                        $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                        if(empty($otpVerifySubmit)){
                            // handle payment
                            $lastsessionData = Session::where('session_id', $SessionId)
                                        ->whereNotNull('message')
                                        ->whereNotNull('request_json')
                                        ->whereNull('response_json')
                                        ->orderBy('id', 'desc')
                                        ->skip(1)
                                        ->first();
                            $payment_system = $lastsessionData->message;
                            if($payment_system == 1){
                                $Pay_Role = "DAILY";
                            } else if($payment_system == 2) {
                                $Pay_Role = "WEEKLY";
                            } else {
                                $Pay_Role = "MONTHLY";
                            }
                            Session::create([
                                'session_id' => $SessionId,
                                'payment_system' => $payment_system,
                            ]);
                            
                            $company_cred = Company::where('company_id', $company_id)->first();
                            $api_id = $company_cred->api_id;
                            $pos_sales_id = $company_cred->pos_sales_id;
                            $api_key = $company_cred->api_key;

                            Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                            $token = base64_encode($api_id.":".$api_key);
                        
                            $response = Http::withHeaders([
                                'Authorization' => "Basic {$token}",
                                'Content-Type' => 'application/json'
                            ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                                "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                                "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                                "description" => $product_name->name,
                                "startTime" => now()->addMinutes(5)->format('H:i'),
                                "paymentInterval" => $Pay_Role,
                                "customerMobileNumber" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                                "paymentOption" => "MobileMoney",
                                "channel" => $Operator,
                                "customerName" => $customer->name,
                                "recurringAmount" => $Entered_amount->message,
                                "totalAmount" => $Entered_amount->message,
                                "initialAmount" => $Entered_amount->message,
                                "currency" => "GHS",
                                "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                            ]);

                            Log::info("Response Status: " . $response->status());
                            Log::info("response", ['body' => $response->getBody()->getContents()]);

                            $company_otp = Company::where('company_id', $company_id)->first();

                            $data = json_decode($response, true);
                            if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                                $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                                Session::create([
                                    'session_id' => $SessionId,
                                    'recurring_invoice_id' => $recurringInvoiceId,
                                ]);

                                $customer_id = Customer::where('phone_number',$Mobile)->first();

                                Transaction::create([ 
                                    'customer_id' => $customer_id->id,
                                    'name'=> $product_name->name,
                                    'session_id' => $SessionId,
                                    'phone_number'=> $Mobile,
                                    'recurring_invoice_id' => $recurringInvoiceId,
                                    'status' => 'pending',
                                    'amount' => $Entered_amount->message,
                                    'company_id' => $company_id,
                                    'description' => 'Product',
                                    'selected_plan_id' => $Select_Plan->message
                                ]);
                                
                                Log::info("response:{$response}");

                                $message = "Please check sms for status of transaction.";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            }
                            $requestId = $data['data']['requestId'];
                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                            $otpPrefix = $data['data']['otpPrefix'];

                            Session::create([
                                'request_id' => $requestId,
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'otp_prefix' => $otpPrefix,
                            ]);

                            $customer_id = Customer::where('phone_number',$Mobile)->first();

                            Transaction::create([ 
                                'customer_id' => $customer_id->id,
                                'name'=> $product_name->name,
                                'request_id' => $requestId,
                                'session_id' => $SessionId,
                                'phone_number'=> $Mobile,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'otpPrefix' => $otpPrefix,
                                'status' => 'pending',
                                'amount' => $Entered_amount->message,
                                'company_id' => $company_id,
                                'description' => 'Product',
                                'selected_plan_id' => $Select_Plan->message
                            ]);
                            
                            Log::info("response:{$response}");
                            
                            $message = "Enter OTP";
                            $label = "PaymentOTP";
                            $dataType = "text";

                        }
                } else {
                    $payment = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(1)
                                    ->first();

                    if($payment->message == '2'){

                    } else {
                        $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                        if (!empty($otpVerifySubmit)) {
                            $company_cred = Company::where('company_id', $company_id)->first();
                            $api_id = $company_cred->api_id;
                            $pos_sales_id = $company_cred->pos_sales_id;
                            $api_key = $company_cred->api_key;

                            $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                            $token = base64_encode($api_id.":".$api_key);
                            $responseOTPVerify = Http::withHeaders([
                                'Authorization' => "Basic {$token}",
                                'Content-Type' => 'application/json'
                            ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                                "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                                "requestId" => $otpVerifySubmit->request_id,
                                "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                            ]);

                            Log::info("Response Status: " . $responseOTPVerify->status());
                            Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                            $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                            if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                                $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                                Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                                $message = "Please check sms for status of transaction!";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            } else {
                                $message = "Please check sms for status of transaction!";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            }
                            
                        }
                    }
                }
                break;
            case '8':
                $PaymentProduct = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(3)
                                ->first();

                $product = Product::where('id', $PaymentProduct->message)->first();

                if($product->amount_type == 'Dynamic'){
                    $payment = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(3)
                                    ->first();

                    if($payment->message == '2'){

                    } else {
                        $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                        if (!empty($otpVerifySubmit)) {
                            $company_cred = Company::where('company_id', $company_id)->first();
                            $api_id = $company_cred->api_id;
                            $pos_sales_id = $company_cred->pos_sales_id;
                            $api_key = $company_cred->api_key;

                            $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                            $token = base64_encode($api_id.":".$api_key);
                            $responseOTPVerify = Http::withHeaders([
                                'Authorization' => "Basic {$token}",
                                'Content-Type' => 'application/json'
                            ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                                "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                                "requestId" => $otpVerifySubmit->request_id,
                                "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                            ]);

                            Log::info("Response Status: " . $responseOTPVerify->status());
                            Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                            $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                            if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                                $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                                Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                                $message = "Please check sms for status of transaction!";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            } else {
                                $message = "Please check sms for status of transaction!";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            }
                            
                        }
                    }
                }
                break;
            
            
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType,
            "internal_number"=> $internal_number
        ];
    }

    public function handleProductScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        $internal_number=0;
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                $internal_number = 2;
                break;
            // case '3':
            //     $message = "Enter Provider\n1. Vodafone\n2.MTN";
            //     $label = "Provider";
            //     $dataType = "text";
            //     $internal_number = 3;
            //     break;
            case '3':
                $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $phoneNumber->message)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $phoneNumber->message){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                
                // $oldCustomerInfo = Customer::where('phone_number', $phoneNumber->message)->where('reset_pin',1)->first();
                // if (!empty($oldCustomerInfo)) {
                //     $message = "As you are requested to reset your account Please enter the otp which comes up in your phone number.";
                //     $label = "PIN";
                //     $dataType = "text";
                // }else{
                    $message = "Enter 4 digits pin";
                    $label = "PIN";
                    $dataType = "text";
                    $internal_number = 4;
                // }
                break;
            case '4':
                $company = Company::where('company_id', $company_id)->first();
                $plans = Product::where('company_id', $company->id)
                     ->get();
                
                $packages = "Choose your plan:";
                foreach ($plans as $plan) {
                    $packages .= "\n" . $plan->id . ". " . $plan->name;
                }

                $message = $packages;
                $label = "Plan";
                $dataType = "text";
                $internal_number = 5;
                break;
            case '5':
                $message = "Select Payment Plan: \n1.Subscribe\n2.Topup";
                $label = "PIN";
                $dataType = "text";
                $internal_number = 6;
                break;
            case '6';
                $payment = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                if($payment->message == '2'){
                    $message = "Enter the Amount";
                    $label = "Amount";
                    $dataType = "text";
                    $internal_number = 7;
                } else {
                    $message = "Select Payment Plan: \n1.Daily\n2.Weekly\n3.Monthly";
                    $label = "PaymentPlan";
                    $dataType = "text";
                    $internal_number = 7;
                }
                break;
            case '7':
                $payment = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                if($payment->message == '2'){
                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(4)
                    //             ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                                
                    $Select_Plan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                                
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $product_name = Product::where('id', $Select_Plan->message)->first();

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        "CustomerMsisdn" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'Product',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $phoneNumber->message,
                    ]);

                    $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'name'=> $product_name->name,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumber->message,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'selected_plan_id' => $Select_Plan->message
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                } else {
                    $PaymentProduct = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();

                    $product = Product::where('id', $PaymentProduct->message)->first();

                    if($product->amount_type == 'Dynamic'){
                        $message = "Enter The Amount";
                        $label = "Amount";
                        $dataType = "text";
                    } else {
                        $phoneNumber = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(4)
                                    ->first();

                        $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                        // $OperatorNumber = Session::where('session_id', $SessionId)
                        //             ->whereNotNull('message')
                        //             ->whereNotNull('request_json')
                        //             ->whereNull('response_json')
                        //             ->orderBy('id', 'desc') 
                        //             ->skip(4)
                        //             ->first();
                        
                        $Select_Plan = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(2)
                                    ->first();

                        $product_name = Product::where('id', $Select_Plan->message)->first();
                                    
                        if($Operator == 'vodafone'){
                            $Operator = 'vodafone_gh_rec';
                        } else {
                            $Operator = 'mtn_gh_rec';
                        }

                        $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                        if(empty($otpVerifySubmit)){
                            // handle payment
                            $lastsessionData = Session::where('session_id', $SessionId)
                                        ->whereNotNull('message')
                                        ->whereNotNull('request_json')
                                        ->whereNull('response_json')
                                        ->orderBy('id', 'desc')
                                        ->first();
                            $payment_system = $lastsessionData->message;
                            if($payment_system == 1){
                                $Pay_Role = "DAILY";
                            } else if($payment_system == 2) {
                                $Pay_Role = "WEEKLY";
                            } else {
                                $Pay_Role = "MONTHLY";
                            }
                            Session::create([
                                'session_id' => $SessionId,
                                'payment_system' => $payment_system,
                            ]);
                            
                            $company_cred = Company::where('company_id', $company_id)->first();
                            $api_id = $company_cred->api_id;
                            $pos_sales_id = $company_cred->pos_sales_id;
                            $api_key = $company_cred->api_key;

                            Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber}");
                            $token = base64_encode($api_id.":".$api_key);
                        
                            $response = Http::withHeaders([
                                'Authorization' => "Basic {$token}",
                                'Content-Type' => 'application/json'
                            ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                                "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                                "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                                "description" => $product_name->name,
                                "startTime" => now()->addMinutes(5)->format('H:i'),
                                "paymentInterval" => $Pay_Role,
                                "customerMobileNumber" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                                "paymentOption" => "MobileMoney",
                                "channel" => $Operator,
                                "customerName" => $customer->name,
                                "recurringAmount" => $product_name->cost,
                                "totalAmount" => $product_name->cost,
                                "initialAmount" => $product_name->cost,
                                "currency" => "GHS",
                                "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                            ]);

                            Log::info("Response Status: " . $response->status());
                            Log::info("response", ['body' => $response->getBody()->getContents()]);

                            $company_otp = Company::where('company_id', $company_id)->first();

                            $data = json_decode($response, true);
                            if((!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix'])) && $company_otp->otp_check == no){
                                $message = "Please check sms for status of transaction.";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            }
                            $requestId = $data['data']['requestId'];
                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                            $otpPrefix = $data['data']['otpPrefix'];

                            Session::create([
                                'request_id' => $requestId,
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'otp_prefix' => $otpPrefix,
                            ]);

                            $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                            Transaction::create([ 
                                'customer_id' => $customer_id->id,
                                'name'=> $product_name->name,
                                'request_id' => $requestId,
                                'session_id' => $SessionId,
                                'phone_number'=> $phoneNumber->message,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'otpPrefix' => $otpPrefix,
                                'status' => 'pending',
                                'amount' => $product_name->cost,
                                'company_id' => $company_id,
                                'description' => 'Product',
                                'selected_plan_id' => $Select_Plan->message
                            ]);
                            
                            Log::info("response:{$response}");
                            
                            $message = "Enter OTP";
                            $label = "PaymentOTP";
                            $dataType = "text";

                        }
                    }
                }
                break;
            case '8':

                $PaymentProduct = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(3)
                                ->first();

                $product = Product::where('id', $PaymentProduct->message)->first();

                if($product->amount_type == 'Dynamic'){
                    $phoneNumber = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(5)
                                    ->first();

                        $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                        // $OperatorNumber = Session::where('session_id', $SessionId)
                        //             ->whereNotNull('message')
                        //             ->whereNotNull('request_json')
                        //             ->whereNull('response_json')
                        //             ->orderBy('id', 'desc') 
                        //             ->skip(5)
                        //             ->first();
                        
                        $Select_Plan = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(3)
                                    ->first();

                        $Entered_amount = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->first();

                        $product_name = Product::where('id', $Select_Plan->message)->first();
                                    
                        if($Operator == 'vodafone'){
                            $Operator = 'vodafone_gh_rec';
                        } else {
                            $Operator = 'mtn_gh_rec';
                        }

                        $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                        if(empty($otpVerifySubmit)){
                            // handle payment
                            $lastsessionData = Session::where('session_id', $SessionId)
                                        ->whereNotNull('message')
                                        ->whereNotNull('request_json')
                                        ->whereNull('response_json')
                                        ->orderBy('id', 'desc')
                                        ->skip(1)
                                        ->first();
                            $payment_system = $lastsessionData->message;
                            if($payment_system == 1){
                                $Pay_Role = "DAILY";
                            } else if($payment_system == 2) {
                                $Pay_Role = "WEEKLY";
                            } else {
                                $Pay_Role = "MONTHLY";
                            }
                            Session::create([
                                'session_id' => $SessionId,
                                'payment_system' => $payment_system,
                            ]);
                            
                            $company_cred = Company::where('company_id', $company_id)->first();
                            $api_id = $company_cred->api_id;
                            $pos_sales_id = $company_cred->pos_sales_id;
                            $api_key = $company_cred->api_key;

                            Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber}");
                            $token = base64_encode($api_id.":".$api_key);
                        
                            $response = Http::withHeaders([
                                'Authorization' => "Basic {$token}",
                                'Content-Type' => 'application/json'
                            ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                                "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                                "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                                "description" => $product_name->name,
                                "startTime" => now()->addMinutes(5)->format('H:i'),
                                "paymentInterval" => $Pay_Role,
                                "customerMobileNumber" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                                "paymentOption" => "MobileMoney",
                                "channel" => $Operator,
                                "customerName" => $customer->name,
                                "recurringAmount" => $Entered_amount->message,
                                "totalAmount" => $Entered_amount->message,
                                "initialAmount" => $Entered_amount->message,
                                "currency" => "GHS",
                                "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                            ]);

                            Log::info("Response Status: " . $response->status());
                            Log::info("response", ['body' => $response->getBody()->getContents()]);

                            $company_otp = Company::where('company_id', $company_id)->first();

                            $data = json_decode($response, true);
                            if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                                $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                                Session::create([
                                    'session_id' => $SessionId,
                                    'recurring_invoice_id' => $recurringInvoiceId,
                                ]);

                                $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                                Transaction::create([ 
                                    'customer_id' => $customer_id->id,
                                    'name'=> $product_name->name,
                                    'session_id' => $SessionId,
                                    'phone_number'=> $phoneNumber->message,
                                    'recurring_invoice_id' => $recurringInvoiceId,
                                    'status' => 'pending',
                                    'amount' => $Entered_amount->message,
                                    'company_id' => $company_id,
                                    'description' => 'Product',
                                    'selected_plan_id' => $Select_Plan->message
                                ]);
                                
                                Log::info("response:{$response}");

                                $message = "Please check sms for status of transaction.";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            }
                            $requestId = $data['data']['requestId'];
                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                            $otpPrefix = $data['data']['otpPrefix'];

                            Session::create([
                                'request_id' => $requestId,
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'otp_prefix' => $otpPrefix,
                            ]);

                            $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                            Transaction::create([ 
                                'customer_id' => $customer_id->id,
                                'name'=> $product_name->name,
                                'request_id' => $requestId,
                                'session_id' => $SessionId,
                                'phone_number'=> $phoneNumber->message,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'otpPrefix' => $otpPrefix,
                                'status' => 'pending',
                                'amount' => $Entered_amount->message,
                                'company_id' => $company_id,
                                'description' => 'Product',
                                'selected_plan_id' => $Select_Plan->message
                            ]);
                            
                            Log::info("response:{$response}");
                            
                            $message = "Enter OTP";
                            $label = "PaymentOTP";
                            $dataType = "text";

                        }
                } else {
                    $payment = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(2)
                                    ->first();

                    if($payment->message == '2'){

                    } else {
                        $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                        if (!empty($otpVerifySubmit)) {
                            $company_cred = Company::where('company_id', $company_id)->first();
                            $api_id = $company_cred->api_id;
                            $pos_sales_id = $company_cred->pos_sales_id;
                            $api_key = $company_cred->api_key;

                            $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                            $token = base64_encode($api_id.":".$api_key);
                            $responseOTPVerify = Http::withHeaders([
                                'Authorization' => "Basic {$token}",
                                'Content-Type' => 'application/json'
                            ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                                "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                                "requestId" => $otpVerifySubmit->request_id,
                                "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                            ]);

                            Log::info("Response Status: " . $responseOTPVerify->status());
                            Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                            $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                            if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                                $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                                Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                                $message = "Please check sms for status of transaction!";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            } else {
                                $message = "Please check sms for status of transaction!";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            }
                            
                        }
                    }
                }
                break;
            case '9':
                $PaymentProduct = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();

                $product = Product::where('id', $PaymentProduct->message)->first();

                if($product->amount_type == 'Dynamic'){
                    $payment = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->skip(3)
                                    ->first();

                    if($payment->message == '2'){

                    } else {
                        $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                        if (!empty($otpVerifySubmit)) {
                            $company_cred = Company::where('company_id', $company_id)->first();
                            $api_id = $company_cred->api_id;
                            $pos_sales_id = $company_cred->pos_sales_id;
                            $api_key = $company_cred->api_key;

                            $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                            $token = base64_encode($api_id.":".$api_key);
                            $responseOTPVerify = Http::withHeaders([
                                'Authorization' => "Basic {$token}",
                                'Content-Type' => 'application/json'
                            ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                                "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                                "requestId" => $otpVerifySubmit->request_id,
                                "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                            ]);

                            Log::info("Response Status: " . $responseOTPVerify->status());
                            Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                            $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                            if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                                $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                                Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                                $message = "Please check sms for status of transaction!";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            } else {
                                $message = "Please check sms for status of transaction!";
                                $label = "Transaction";
                                $dataType = "display";
                                return [
                                    "message" => $message,
                                    "label"=>$label,
                                    "data_type"=>$dataType
                                ];
                            }
                            
                        }
                    }
                }
                break;
            
            
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType,
            "internal_number"=> $internal_number
        ];
    }

    public function hubtelUSSDtest(){
        
        $token = base64_encode("lRk35Zg:221d0bb469cb4a9da90c198190db640a"); 
        $response = Http::withHeaders([
            'Authorization' => "Basic {$token}",
            'Content-Type' => 'application/json'
        ])->post('https://rip.hubtel.com/api/proxy/2023714/create-invoice', [
            "orderDate" => now()->addDays(1)->format('Y-m-d\TH:i:s'),
            "invoiceEndDate" => now()->addDays(3)->format('Y-m-d\TH:i:s'),
            "description" => "Extreme Gaming Service",
            "startTime" => now()->format('H:i'),
            "paymentInterval" => "DAILY",
            "customerMobileNumber" => "233200777262",
            "paymentOption" => "MobileMoney",
            "channel" => "vodafone_gh_rec",
            "customerName" => "Bhavik Chudashama",
            "recurringAmount" => 0.01,
            "totalAmount" => 0.01,
            "initialAmount" => 0.01,
            "currency" => "GHS",
            "callbackUrl" => "https://ussd.atdamss.com/api/ussd/callback"
        ]);
        // dd($response->getBody()->getContents());
    }

    public function handleCheckBalanceScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            // case '2':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     break;
            case '2':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '3':
                // $phoneNumberforBalance = Session::where('session_id', $SessionId)
                //                 ->whereNotNull('message')
                //                 ->whereNotNull('request_json')
                //                 ->whereNull('response_json')
                //                 ->orderBy('id', 'desc') 
                //                 ->skip(1) 
                //                 ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                $balance = Customer::where('phone_number', $Mobile)->where('pin', $PINforBalance->message)->first();
                if (empty($balance)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $company_details = Company::where('company_id', $company_id)->first();
                $balance_amount =(!empty($balance) && !empty($balance->balance)) ? floatval($balance->balance) : 0;

                $message = "\n";
                $org_dues = 0;
                $members_welfare = 0;
                $loan_balance_amount = (!empty($balance) && !empty($balance->loan_balance)) ? "{$balance->loan_balance} GHS" : '0 GHS';
                    
                $message .= "Name: {$balance->name}\nPhone Number: {$Mobile}";
                if($company_details->org_dues == 'yes'){
                    $org_dues = $company_details->org_dues_balance ?? 0;
                    $message .= "\nOrg. Dues: " . $company_details->org_dues_balance ?? 0 . " GHS." ;
                }
                if($company_details->members_welfare == 'yes'){
                    $members_welfare = $company_details->members_welfare_balance ?? 0;
                    $message .= "\nMembers's Welfare: " . $balance->members_welfare ?? 0 . " GHS.";
                }
                $final_total_balance = $balance_amount;
                if($company_details->show_service_charge == 'yes'){
                    $message .= "\nService Charge : " . $balance->service_charge ?? 0 . " GHS.";
                }
                if(isset($balance->loan_balance) && !empty($balance->loan_balance)){
                    $message .= "\nWallet Balance: ". $final_total_balance ?? 0 . " GHS\nLoan Balance: " . $loan_balance_amount ?? 0 . "GHS";
                } else {
                    $message .= "\nBalance: ". $final_total_balance ?? 0 . "GHS";
                }
                $label = "Balance";
                $dataType = "text";
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleCheckBalanceScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $phoneNumberforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1) 
                                ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $balance = Customer::where('phone_number', $phoneNumberforBalance->message)->where('pin', $PINforBalance->message)->first();
                if (empty($balance)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $company_details = Company::where('company_id', $company_id)->first();
                $balance_amount =(!empty($balance) && !empty($balance->balance)) ? floatval($balance->balance) : 0;

                $message = "\n";
                $org_dues = 0;
                $members_welfare = 0;
                $loan_balance_amount = (!empty($balance) && !empty($balance->loan_balance)) ? "{$balance->loan_balance} GHS" : '0 GHS';
                    
                $message .= "Name: {$balance->name}\nPhone Number: {$balance->phone_number}";
                if($company_details->org_dues == 'yes'){
                    $org_dues = $company_details->org_dues_balance ?? 0;
                    $message .= "\nOrg. Dues: " . $company_details->org_dues_balance ?? 0 . " GHS." ;
                }
                if($company_details->members_welfare == 'yes'){
                    $members_welfare = $company_details->members_welfare_balance ?? 0;
                    $message .= "\nMembers's Welfare: " . $balance->members_welfare ?? 0 . " GHS.";
                }
                $final_total_balance = $balance_amount;
                if($company_details->show_service_charge == 'yes'){
                    $message .= "\nService Charge : " . $balance->service_charge ?? 0 . " GHS.";
                }
                if(isset($balance->loan_balance) && !empty($balance->loan_balance)){
                    $message .= "\nWallet Balance: ". $final_total_balance ?? 0 . " GHS\nLoan Balance: " . $loan_balance_amount ?? 0 . "GHS";
                } else {
                    $message .= "\nBalance: ". $final_total_balance ?? 0 . "GHS";
                }
                $label = "Balance";
                $dataType = "text";
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleDuesScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Select the Payment Plan.\n1.Subscription.\n2.Topup.";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            // case '3':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     break;
            case '3':
                // $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                //             ->whereNotNull('message')
                //             ->whereNotNull('request_json')
                //             ->whereNull('response_json')
                //             ->orderBy('id', 'desc')
                //             ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $Mobile)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $Mobile){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                // $PhoneNumberForDues = Session::where('session_id', $SessionId)
                //                 ->whereNotNull('message')
                //                 ->whereNotNull('request_json')
                //                 ->whereNull('response_json')
                //                 ->orderBy('id', 'desc') 
                //                 ->skip(1)
                //                 ->first();
                
                $PINForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $Mobile)->where('pin', $PINForDues->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Enter the Amount";
                $label = "amount";
                $dataType = "text";
                break;
            // case '6':
            //     $message = "Select Network.\n1.Vodafone.\n2.MTN";
            //     $label = "network";
            //     $dataType = "text";
            //     break;
            case '5':
                $PaymentType = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                if($PaymentType->message == '2'){

                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(2)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        //"CustomerMsisdn" => '233200777262',
                        "CustomerMsisdn" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'Dues',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $Mobile,
                    ]);

                    $customer_id = Customer::where('phone_number',$Mobile)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'name'=> $customer->name,
                        'session_id' => $SessionId,
                        'phone_number'=> $Mobile,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];

                } else {
                    $message = "Select Payment Plan.\n1.Daily.\n2.Weekly\n3.Monthly";
                    $label = "plan";
                    $dataType = "text";
                }
                break;
            case '6':
                $PaymentType = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(3)
                            ->first();
                if($PaymentType->message == '2'){
                    // Payment Done
                    $message = "Make the payment";
                    $label = "plan";
                    $dataType = "text";
                } else {
                    $PaymentPlan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(3)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(1)
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone_gh_rec';
                    } else {
                        $Operator = 'mtn_gh_rec';
                    }

                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                    if(empty($otpVerifySubmit)){
                        // handle payment
                        $lastsessionData = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc')
                                    ->first();
                        $payment_system = $lastsessionData->message;
                        if($payment_system == 1){
                            $Pay_Role = "DAILY";
                        } else if($payment_system == 2) {
                            $Pay_Role = "WEEKLY";
                        } else {
                            $Pay_Role = "MONTHLY";
                        }
                        Session::create([
                            'session_id' => $SessionId,
                            'payment_system' => $payment_system,
                        ]);
                        
                        $company_cred = Company::where('company_id', $company_id)->first();
                        $api_id = $company_cred->api_id;
                        $pos_sales_id = $company_cred->pos_sales_id;
                        $api_key = $company_cred->api_key;

                        Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                        $token = base64_encode($api_id.":".$api_key);
                    
                        $response = Http::withHeaders([
                            'Authorization' => "Basic {$token}",
                            'Content-Type' => 'application/json'
                        ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                            "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                            "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                            "description" => 'Dues',
                            "startTime" => now()->addMinutes(5)->format('H:i'),
                            "paymentInterval" => $Pay_Role,
                            "customerMobileNumber" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                            "paymentOption" => "MobileMoney",
                            "channel" => $Operator,
                            "customerName" => $customer->name,
                            "recurringAmount" => $Amount->message,
                            "totalAmount" => $Amount->message,
                            "initialAmount" => $Amount->message,
                            "currency" => "GHS",
                            "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                        ]);

                        Log::info("Response Status: " . $response->status());
                        Log::info("response", ['body' => $response->getBody()->getContents()]);

                        $company_otp = Company::where('company_id', $company_id)->first();

                        $data = json_decode($response, true);
                        if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){
                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                            Session::create([
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                            ]);

                            $customer_id = Customer::where('phone_number',$Mobile)->first();

                            Transaction::create([
                                'customer_id' => $customer_id->id,
                                'name'=> $customer->name,
                                'session_id' => $SessionId,
                                'phone_number'=> $Mobile,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'status' => 'pending',
                                'amount' => $Amount->message,
                                'company_id' => $company_id,
                                'description' => 'Dues'
                            ]);
                            
                            Log::info("response:{$response}");

                            $message = "Please check sms for status of transaction.";
                            $label = "Transaction";
                            $dataType = "display";

                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }
                        $requestId = $data['data']['requestId'];
                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                        $otpPrefix = $data['data']['otpPrefix'];

                        Session::create([
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otp_prefix' => $otpPrefix,
                        ]);

                        $customer_id = Customer::where('phone_number',$Mobile)->first();

                        Transaction::create([
                            'customer_id' => $customer_id->id,
                            'name'=> $customer->name,
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'phone_number'=> $Mobile,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otpPrefix' => $otpPrefix,
                            'status' => 'pending',
                            'amount' => $Amount->message,
                            'company_id' => $company_id,
                            'description' => 'Dues'
                        ]);
                        
                        Log::info("response:{$response}");
                        
                        $message = "Enter OTP";
                        $label = "PaymentOTP";
                        $dataType = "text";

                    }
                }
                break;
            case '7':
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if (!empty($otpVerifySubmit)) {
                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);

                    Log::info("Response Status: " . $responseOTPVerify->status());
                    Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction.";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleDuesScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Select the Payment Plan.\n1.Subscription.\n2.Topup.";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '4':
                $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $phoneNumber->message)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $phoneNumber->message){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '5':
                $PhoneNumberForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                
                $PINForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $PhoneNumberForDues->message)->where('pin', $PINForDues->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Enter the Amount";
                $label = "amount";
                $dataType = "text";
                break;
            case '6':
                $message = "Select Network.\n1.Vodafone.\n2.MTN";
                $label = "network";
                $dataType = "text";
                break;
            case '7':
                $PaymentType = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();
                if($PaymentType->message == '2'){

                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(3)
                                ->first();

                    $OperatorNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                                
                    if($OperatorNumber == '1'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        //"CustomerMsisdn" => '233200777262',
                        "CustomerMsisdn" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'Dues',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $phoneNumber->message,
                    ]);

                    $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'name'=> $customer->name,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumber->message,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];

                } else {
                    $message = "Select Payment Plan.\n1.Daily.\n2.Weekly\n3.Monthly";
                    $label = "plan";
                    $dataType = "text";
                }
                break;
            case '8':
                $PaymentType = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(5)
                            ->first();

                if($PaymentType->message == '2'){
                    // Payment Done
                    $message = "Make the payment";
                    $label = "plan";
                    $dataType = "text";
                } else {
                    $PaymentPlan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();

                    $OperatorNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                                
                    if($OperatorNumber == '1'){
                        $Operator = 'vodafone_gh_rec';
                    } else {
                        $Operator = 'mtn_gh_rec';
                    }

                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                    if(empty($otpVerifySubmit)){
                        // handle payment
                        $lastsessionData = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc')
                                    ->first();
                        $payment_system = $lastsessionData->message;
                        if($payment_system == 1){
                            $Pay_Role = "DAILY";
                        } else if($payment_system == 2) {
                            $Pay_Role = "WEEKLY";
                        } else {
                            $Pay_Role = "MONTHLY";
                        }
                        Session::create([
                            'session_id' => $SessionId,
                            'payment_system' => $payment_system,
                        ]);
                        
                        $company_cred = Company::where('company_id', $company_id)->first();
                        $api_id = $company_cred->api_id;
                        $pos_sales_id = $company_cred->pos_sales_id;
                        $api_key = $company_cred->api_key;

                        Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                        $token = base64_encode($api_id.":".$api_key);
                    
                        $response = Http::withHeaders([
                            'Authorization' => "Basic {$token}",
                            'Content-Type' => 'application/json'
                        ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                            "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                            "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                            "description" => 'Dues',
                            "startTime" => now()->addMinutes(5)->format('H:i'),
                            "paymentInterval" => $Pay_Role,
                            "customerMobileNumber" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                            "paymentOption" => "MobileMoney",
                            "channel" => $Operator,
                            "customerName" => $customer->name,
                            "recurringAmount" => $Amount->message,
                            "totalAmount" => $Amount->message,
                            "initialAmount" => $Amount->message,
                            "currency" => "GHS",
                            "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                        ]);

                        Log::info("Response Status: " . $response->status());
                        Log::info("response", ['body' => $response->getBody()->getContents()]);

                        $company_otp = Company::where('company_id', $company_id)->first();

                        $data = json_decode($response, true);
                        if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){
                            $recurringInvoiceId = !empty($data['data']) && !empty($data['data']['recurringInvoiceId']) ? $data['data']['recurringInvoiceId'] : "";
                            
                            Session::create([
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                            ]);

                            $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                            Transaction::create([
                                'customer_id' => $customer_id->id,
                                'name'=> $customer->name,
                                'session_id' => $SessionId,
                                'phone_number'=> $phoneNumber->message,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'status' => 'pending',
                                'amount' => $Amount->message,
                                'company_id' => $company_id,
                                'description' => 'Dues'
                            ]);
                            
                            Log::info("response:{$response}");

                            $message = "Please check sms for status of transaction.";
                            $label = "Transaction";
                            $dataType = "display";

                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }
                        $requestId = $data['data']['requestId'];
                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                        $otpPrefix = $data['data']['otpPrefix'];

                        Session::create([
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otp_prefix' => $otpPrefix,
                        ]);

                        $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                        Transaction::create([
                            'customer_id' => $customer_id->id,
                            'name'=> $customer->name,
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'phone_number'=> $phoneNumber->message,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otpPrefix' => $otpPrefix,
                            'status' => 'pending',
                            'amount' => $Amount->message,
                            'company_id' => $company_id,
                            'description' => 'Dues'
                        ]);
                        
                        Log::info("response:{$response}");
                        
                        $message = "Enter OTP";
                        $label = "PaymentOTP";
                        $dataType = "text";

                    }
                }
                break;
            case '9':
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if (!empty($otpVerifySubmit)) {
                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);

                    Log::info("Response Status: " . $responseOTPVerify->status());
                    Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction.";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handlePayFeesScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Select the Payment Plan.\n1.Subscription.\n2.Topup.";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            // case '3':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     break;
            case '3':
                // $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                //             ->whereNotNull('message')
                //             ->whereNotNull('request_json')
                //             ->whereNull('response_json')
                //             ->orderBy('id', 'desc')
                //             ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $Mobile)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $Mobile){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $PhoneNumberForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                
                $PINForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $Mobile)->where('pin', $PINForDues->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Enter the Amount";
                $label = "amount";
                $dataType = "text";
                break;
            // case '6':
            //     $message = "Select Network.\n1.Vodafone.\n2.MTN";
            //     $label = "network";
            //     $dataType = "text";
            //     break;
            case '5':
                $PaymentType = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                if($PaymentType->message == '2'){

                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(2)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                    $token = base64_encode($api_id.":".$api_key);
                    Log::info("Payment Initiated sessionID again :{$SessionId} with token:{$token}");

                    Log::info("Hubtel Payment Request", [
                        'url'  => "'https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney'",
                        'body' => json_encode([
                            "CustomerName" => $customer->name,
                            //"CustomerMsisdn" => '233200777262',
                            "CustomerMsisdn" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                            "Channel" => $Operator,
                            "Amount" => $Amount->message,
                            "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                            "Description" => 'PayFees',
                            "ClientReference" => $clientReference
                        ])
                    ]);
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        //"CustomerMsisdn" => '233200777262',
                        "CustomerMsisdn" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'PayFees',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $Mobile,
                    ]);

                    $customer_id = Customer::where('phone_number',$Mobile)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'name'=> $customer->name,
                        'session_id' => $SessionId,
                        'phone_number'=> $Mobile,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];

                } else {
                    $message = "Select Payment Plan.\n1.Daily.\n2.Weekly\n3.Monthly";
                    $label = "plan";
                    $dataType = "text";
                }
                break;
            case '6':
                $PaymentType = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(3)
                            ->first();

                if($PaymentType->message == '2'){
                    // Payment Initiation
                    $message = "Make the payment";
                    $label = "plan";
                    $dataType = "text";
                } else {
                    $PaymentPlan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(3)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(1)
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone_gh_rec';
                    } else {
                        $Operator = 'mtn_gh_rec';
                    }

                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                    if(empty($otpVerifySubmit)){
                        // handle payment
                        $lastsessionData = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc')
                                    ->first();
                        $payment_system = $lastsessionData->message;
                        if($payment_system == 1){
                            $Pay_Role = "DAILY";
                        } else if($payment_system == 2) {
                            $Pay_Role = "WEEKLY";
                        } else {
                            $Pay_Role = "MONTHLY";
                        }
                        Session::create([
                            'session_id' => $SessionId,
                            'payment_system' => $payment_system,
                        ]);
                        
                        $company_cred = Company::where('company_id', $company_id)->first();
                        $api_id = $company_cred->api_id;
                        $pos_sales_id = $company_cred->pos_sales_id;
                        $api_key = $company_cred->api_key;

                        Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                        $token = base64_encode($api_id.":".$api_key);
                    
                        $response = Http::withHeaders([
                            'Authorization' => "Basic {$token}",
                            'Content-Type' => 'application/json'
                        ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                            "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                            "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                            "description" => 'PayFees',
                            "startTime" => now()->addMinutes(5)->format('H:i'),
                            "paymentInterval" => $Pay_Role,
                            "customerMobileNumber" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                            "paymentOption" => "MobileMoney",
                            "channel" => $Operator,
                            "customerName" => $customer->name,
                            "recurringAmount" => $Amount->message,
                            "totalAmount" => $Amount->message,
                            "initialAmount" => $Amount->message,
                            "currency" => "GHS",
                            "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                        ]);

                        Log::info("Response Status: " . $response->status());
                        Log::info("response", ['body' => $response->getBody()->getContents()]);

                        $company_otp = Company::where('company_id', $company_id)->first();

                        $data = json_decode($response, true);
                        if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                            Session::create([
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                            ]);

                            $customer_id = Customer::where('phone_number',$Mobile)->first();

                            Transaction::create([
                                'customer_id' => $customer_id->id,
                                'name'=> $customer->name,
                                'session_id' => $SessionId,
                                'phone_number'=> $Mobile,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'status' => 'pending',
                                'amount' => $Amount->message,
                                'company_id' => $company_id,
                                'description' => 'PayFees'
                            ]);
                            
                            Log::info("response:{$response}");

                            $message = "Please check sms for status of transaction.";
                            $label = "Transaction";
                            $dataType = "display";
                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }
                        $requestId = $data['data']['requestId'];
                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                        $otpPrefix = $data['data']['otpPrefix'];

                        Session::create([
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otp_prefix' => $otpPrefix,
                        ]);

                        $customer_id = Customer::where('phone_number',$Mobile)->first();

                        Transaction::create([
                            'customer_id' => $customer_id->id,
                            'name'=> $customer->name,
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'phone_number'=> $Mobile,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otpPrefix' => $otpPrefix,
                            'status' => 'pending',
                            'amount' => $Amount->message,
                            'company_id' => $company_id,
                            'description' => 'PayFees'
                        ]);
                        
                        Log::info("response:{$response}");
                        
                        $message = "Enter OTP";
                        $label = "PaymentOTP";
                        $dataType = "text";

                    }
                }
                break;
            case '7':
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if (!empty($otpVerifySubmit)) {
                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);

                    Log::info("Response Status: " . $responseOTPVerify->status());
                    Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handlePayFeesScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Select the Payment Plan.\n1.Subscription.\n2.Topup.";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '4':
                $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $phoneNumber->message)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $phoneNumber->message){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '5':
                $PhoneNumberForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                
                $PINForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $PhoneNumberForDues->message)->where('pin', $PINForDues->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Enter the Amount";
                $label = "amount";
                $dataType = "text";
                break;
            case '6':
                $message = "Select Network.\n1.Vodafone.\n2.MTN";
                $label = "network";
                $dataType = "text";
                break;
            case '7':
                $PaymentType = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();
                if($PaymentType->message == '2'){

                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(3)
                                ->first();

                    $OperatorNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                                
                    if($OperatorNumber == '1'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        //"CustomerMsisdn" => '233200777262',
                        "CustomerMsisdn" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'PayFees',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $phoneNumber->message,
                    ]);

                    $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'name'=> $customer->name,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumber->message,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];

                } else {
                    $message = "Select Payment Plan.\n1.Daily.\n2.Weekly\n3.Monthly";
                    $label = "plan";
                    $dataType = "text";
                }
                break;
            case '8':
                $PaymentType = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(5)
                            ->first();

                if($PaymentType->message == '2'){
                    // Payment Initiation
                    $message = "Make the payment";
                    $label = "plan";
                    $dataType = "text";
                } else {
                    $PaymentPlan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();

                    $OperatorNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                                
                    if($OperatorNumber == '1'){
                        $Operator = 'vodafone_gh_rec';
                    } else {
                        $Operator = 'mtn_gh_rec';
                    }

                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                    if(empty($otpVerifySubmit)){
                        // handle payment
                        $lastsessionData = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc')
                                    ->first();
                        $payment_system = $lastsessionData->message;
                        if($payment_system == 1){
                            $Pay_Role = "DAILY";
                        } else if($payment_system == 2) {
                            $Pay_Role = "WEEKLY";
                        } else {
                            $Pay_Role = "MONTHLY";
                        }
                        Session::create([
                            'session_id' => $SessionId,
                            'payment_system' => $payment_system,
                        ]);
                        
                        $company_cred = Company::where('company_id', $company_id)->first();
                        $api_id = $company_cred->api_id;
                        $pos_sales_id = $company_cred->pos_sales_id;
                        $api_key = $company_cred->api_key;

                        Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                        $token = base64_encode($api_id.":".$api_key);
                    
                        $response = Http::withHeaders([
                            'Authorization' => "Basic {$token}",
                            'Content-Type' => 'application/json'
                        ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                            "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                            "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                            "description" => 'PayFees',
                            "startTime" => now()->addMinutes(5)->format('H:i'),
                            "paymentInterval" => $Pay_Role,
                            "customerMobileNumber" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                            "paymentOption" => "MobileMoney",
                            "channel" => $Operator,
                            "customerName" => $customer->name,
                            "recurringAmount" => $Amount->message,
                            "totalAmount" => $Amount->message,
                            "initialAmount" => $Amount->message,
                            "currency" => "GHS",
                            "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                        ]);

                        Log::info("Response Status: " . $response->status());
                        Log::info("response", ['body' => $response->getBody()->getContents()]);

                        $company_otp = Company::where('company_id', $company_id)->first();

                        $data = json_decode($response, true);
                        if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                            Session::create([
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                            ]);

                            $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                            Transaction::create([
                                'customer_id' => $customer_id->id,
                                'name'=> $customer->name,
                                'session_id' => $SessionId,
                                'phone_number'=> $phoneNumber->message,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'status' => 'pending',
                                'amount' => $Amount->message,
                                'company_id' => $company_id,
                                'description' => 'PayFees'
                            ]);
                            
                            Log::info("response:{$response}");

                            $message = "Please check sms for status of transaction.";
                            $label = "Transaction";
                            $dataType = "display";
                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }
                        $requestId = $data['data']['requestId'];
                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                        $otpPrefix = $data['data']['otpPrefix'];

                        Session::create([
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otp_prefix' => $otpPrefix,
                        ]);

                        $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                        Transaction::create([
                            'customer_id' => $customer_id->id,
                            'name'=> $customer->name,
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'phone_number'=> $phoneNumber->message,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otpPrefix' => $otpPrefix,
                            'status' => 'pending',
                            'amount' => $Amount->message,
                            'company_id' => $company_id,
                            'description' => 'PayFees'
                        ]);
                        
                        Log::info("response:{$response}");
                        
                        $message = "Enter OTP";
                        $label = "PaymentOTP";
                        $dataType = "text";

                    }
                }
                break;
            case '9':
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if (!empty($otpVerifySubmit)) {
                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);

                    Log::info("Response Status: " . $responseOTPVerify->status());
                    Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleWithdrawlScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            // case '2':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     break;
            case '2':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '3':
                // $phoneNumberforBalance = Session::where('session_id', $SessionId)
                //                 ->whereNotNull('message')
                //                 ->whereNotNull('request_json')
                //                 ->whereNull('response_json')
                //                 ->orderBy('id', 'desc') 
                //                 ->skip(1) 
                //                 ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $balance = Customer::where('phone_number', $Mobile)->where('pin', $PINforBalance->message)->first();
                if (empty($balance)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $balance_amount =(!empty($balance) && !empty($balance->balance)) ? "{$balance->balance} GHS" : '0 GHS';
                $message = "Enter the amount you want to withdraw";
                $label = "Amount";
                $dataType = "text";
                break;
            case '4':
                $BalanceAmount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                // $phoneNumberforBalance = Session::where('session_id', $SessionId)
                // ->whereNotNull('message')
                // ->whereNotNull('request_json')
                // ->whereNull('response_json')
                // ->orderBy('id', 'desc') 
                // ->skip(2) 
                // ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                $balance = Customer::where('phone_number', $Mobile)->where('pin', $PINforBalance->message)->first();
                if($BalanceAmount->message > $balance->balance){
                    $message = "Your wallet doesn't have that much balance";
                    $label = "Amount";
                    $dataType = "text";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Withdrawl Success";
                $label = "Amount";
                $dataType = "text";
                WithdrawlRequest::create([
                    'customer_name' => $balance->name,
                    'customer_phone_number' => $balance->phone_number,
                    'amount' => $BalanceAmount->message,
                    'company_id' => $company_id,
                    'status' => 'pending',
                ]);
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleWithdrawlScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $phoneNumberforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1) 
                                ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $balance = Customer::where('phone_number', $phoneNumberforBalance->message)->where('pin', $PINforBalance->message)->first();
                if (empty($balance)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $balance_amount =(!empty($balance) && !empty($balance->balance)) ? "{$balance->balance} GHS" : '0 GHS';
                $message = "Enter the amount you want to withdraw";
                $label = "Amount";
                $dataType = "text";
                break;
            case '5':
                $BalanceAmount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $phoneNumberforBalance = Session::where('session_id', $SessionId)
                ->whereNotNull('message')
                ->whereNotNull('request_json')
                ->whereNull('response_json')
                ->orderBy('id', 'desc') 
                ->skip(2) 
                ->first();

                $PINforBalance = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                $balance = Customer::where('phone_number', $phoneNumberforBalance->message)->where('pin', $PINforBalance->message)->first();
                if($BalanceAmount->message > $balance->balance){
                    $message = "Your wallet doesn't have that much balance";
                    $label = "Amount";
                    $dataType = "text";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Withdrawl Success";
                $label = "Amount";
                $dataType = "text";
                WithdrawlRequest::create([
                    'customer_name' => $balance->name,
                    'customer_phone_number' => $balance->phone_number,
                    'amount' => $BalanceAmount->message,
                    'company_id' => $company_id,
                    'status' => 'pending',
                ]);
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleLoanRequestScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            // case '2':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     break;
            case '2':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '3':
                // $phoneNumberforLoan = Session::where('session_id', $SessionId)
                //                 ->whereNotNull('message')
                //                 ->whereNotNull('request_json')
                //                 ->whereNull('response_json')
                //                 ->orderBy('id', 'desc') 
                //                 ->skip(1) 
                //                 ->first();

                $PINforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $Mobile)->where('pin', $PINforLoan->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }

                $company = Company::where('company_id', $company_id)->first();
                $LoanProducts = LoanProduct::where('company_id', $company->id)
                                ->get();
                
                $packages = "Choose your LoanProduct:";
                foreach ($LoanProducts as $LoanProduct) {
                    $packages .= "\n" . $LoanProduct->id . ". " . $LoanProduct->name;
                }

                $message = $packages;
                $label = "LoanProduct";
                $dataType = "text";                
                break;
            case '4';
                $SelectedLoanProduct = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->first();
                
                $LoanDetails = LoanProduct::where('id',$SelectedLoanProduct->message)->first();
                
                $message = "Max Amount: " . $LoanDetails->maximum_value . "\nMin Value: " . $LoanDetails->minimum_value . "\nPayment Duration: " . $LoanDetails->repayment_type . "\nEnter amount to request for loan.";
                $label = "SelectedLoanProduct";
                $dataType = "text"; 
                
                break;
            case '5':
                $SelectedLoanProduct = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(1)
                            ->first();
                
                // $phoneNumberforLoan = Session::where('session_id', $SessionId)
                //                 ->whereNotNull('message')
                //                 ->whereNotNull('request_json')
                //                 ->whereNull('response_json')
                //                 ->orderBy('id', 'desc') 
                //                 ->skip(3) 
                //                 ->first();

                $PINforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();

                $AmountforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_name = Customer::where('phone_number', $Mobile)->first();
                $comp = Company::where('company_id',$company_id)->first();
                LoanRequest::create([
                    'customer_name' => $customer_name->name,
                    'customer_phone_number' => $Mobile,
                    'amount' => $AmountforLoan->message,
                    'company_id' => $comp->id,
                    'loan_product_id' => $SelectedLoanProduct->message,
                    'status' => 'Pending'
                ]);

                $message = "LoanRequest Generated Successfully";
                $label = "LoanRequest";
                $dataType = "text";             
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleLoanRequestScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $phoneNumberforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1) 
                                ->first();

                $PINforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $phoneNumberforLoan->message)->where('pin', $PINforLoan->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }

                $company = Company::where('company_id', $company_id)->first();
                $LoanProducts = LoanProduct::where('company_id', $company->id)
                                ->get();
                
                $packages = "Choose your LoanProduct:";
                foreach ($LoanProducts as $LoanProduct) {
                    $packages .= "\n" . $LoanProduct->id . ". " . $LoanProduct->name;
                }

                $message = $packages;
                $label = "LoanProduct";
                $dataType = "text";                
                break;
            case '5';
                $SelectedLoanProduct = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->first();
                
                $LoanDetails = LoanProduct::where('id',$SelectedLoanProduct->message)->first();
                
                $message = "Max Amount: " . $LoanDetails->maximum_value . "\nMin Value: " . $LoanDetails->minimum_value . "\nPayment Duration: " . $LoanDetails->repayment_type . "\nEnter amount to request for loan.";
                $label = "SelectedLoanProduct";
                $dataType = "text"; 
                
                break;
            case '6':
                $SelectedLoanProduct = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(1)
                            ->first();
                
                $phoneNumberforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(3) 
                                ->first();

                $PINforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                $AmountforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_name = Customer::where('phone_number', $phoneNumberforLoan->message)->first();
                $comp = Company::where('company_id',$company_id)->first();
                LoanRequest::create([
                    'customer_name' => $customer_name->name,
                    'customer_phone_number' => $phoneNumberforLoan->message,
                    'amount' => $AmountforLoan->message,
                    'company_id' => $comp->id,
                    'loan_product_id' => $SelectedLoanProduct->message,
                    'status' => 'Pending'
                ]);

                $message = "LoanRequest Generated Successfully";
                $label = "LoanRequest";
                $dataType = "text";             
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleLoanRepaymentScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            // case '2':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     break;
            case '2':
                // $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                //             ->whereNotNull('message')
                //             ->whereNotNull('request_json')
                //             ->whereNull('response_json')
                //             ->orderBy('id', 'desc')
                //             ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $Mobile)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $Mobile){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '3':
                // $phoneNumberforLoan = Session::where('session_id', $SessionId)
                //                 ->whereNotNull('message')
                //                 ->whereNotNull('request_json')
                //                 ->whereNull('response_json')
                //                 ->orderBy('id', 'desc') 
                //                 ->skip(1) 
                //                 ->first();

                $PINforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $Mobile)->where('pin', $PINforLoan->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $balance_amount =(!empty($customer_details) && !empty($customer_details->balance)) ? "{$customer_details->balance} GHS" : '0 GHS';
                $loan_details = Loan::where('customer_id', $customer_details->id)->first();

                $message = "Hello " . $customer_details->name . "\nLoan Outstanding Amount: " . $loan_details->remaining_payment . "\n1.Subscription.\n2.Topup.";
                $label = "Customer Details";
                $dataType = "text";
                break;
            // case '5':
            //     $message = "Select Network.\n1.Vodafone.\n2.MTN";
            //     $label = "network";
            //     $dataType = "text";
            //     break;
            case '4':
                $message = "Enter the amount";
                $label = "network";
                $dataType = "text";
                break;
            case '5':
                $PaymentType = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                // ->skip(1)
                                ->first();
                if($PaymentType->message == '2'){

                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(3)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(1)
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        //"CustomerMsisdn" => '233200777262',
                        "CustomerMsisdn" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'LoanRepayment',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $phoneNumber->message,
                    ]);

                    $customer_id = Customer::where('phone_number',$Mobile)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'name' => $customer->name,
                        'session_id' => $SessionId,
                        'phone_number'=> $Mobile,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];

                } else {
                    $message = "Select Payment Plan.\n1.Daily.\n2.Weekly\n3.Monthly";
                    $label = "plan";
                    $dataType = "text";
                }
                break;
            case '6':
                $PaymentType = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(2) 
                            ->first();

                if($PaymentType->message == '2'){
                    // Payment Initiation
                    $message = "Make the payment";
                    $label = "plan";
                    $dataType = "text";
                } else {
                    $PaymentPlan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(4)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(2)
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone_gh_rec';
                    } else {
                        $Operator = 'mtn_gh_rec';
                    }

                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                    if(empty($otpVerifySubmit)){
                        // handle payment
                        $lastsessionData = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc')
                                    ->first();
                        $payment_system = $lastsessionData->message;
                        if($payment_system == 1){
                            $Pay_Role = "DAILY";
                        } else if($payment_system == 2) {
                            $Pay_Role = "WEEKLY";
                        } else {
                            $Pay_Role = "MONTHLY";
                        }
                        Session::create([
                            'session_id' => $SessionId,
                            'payment_system' => $payment_system,
                        ]);

                        $company_cred = Company::where('company_id', $company_id)->first();
                        $api_id = $company_cred->api_id;
                        $pos_sales_id = $company_cred->pos_sales_id;
                        $api_key = $company_cred->api_key;
                        
                        Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                        $token = base64_encode($api_id.":".$api_key);
                    
                        $response = Http::withHeaders([
                            'Authorization' => "Basic {$token}",
                            'Content-Type' => 'application/json'
                        ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                            "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                            "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                            "description" => 'SusuSavings',
                            "startTime" => now()->addMinutes(5)->format('H:i'),
                            "paymentInterval" => $Pay_Role,
                            "customerMobileNumber" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                            "paymentOption" => "MobileMoney",
                            "channel" => $Operator,
                            "customerName" => $customer->name,
                            "recurringAmount" => $Amount->message,
                            "totalAmount" => $Amount->message,
                            "initialAmount" => $Amount->message,
                            "currency" => "GHS",
                            "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                        ]);

                        Log::info("Response Status: " . $response->status());
                        Log::info("response", ['body' => $response->getBody()->getContents()]);

                        $company_otp = Company::where('company_id', $company_id)->first();

                        $data = json_decode($response, true);
                        if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                            Session::create([
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                            ]);

                            $customer_id = Customer::where('phone_number',$Mobile)->first();

                            Transaction::create([
                                'customer_id' => $customer_id->id,
                                'name'=> $customer->name,
                                'session_id' => $SessionId,
                                'phone_number'=> $Mobile,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'status' => 'pending',
                                'amount' => $Amount->message,
                                'company_id' => $company_id,
                                'description' => 'LoanRepayment'
                            ]);

                            Log::info("response:{$response}");
                            $message = "Please check sms for status of transaction.";
                            $label = "Transaction";
                            $dataType = "display";
                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }
                        $requestId = $data['data']['requestId'];
                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                        $otpPrefix = $data['data']['otpPrefix'];

                        Session::create([
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otp_prefix' => $otpPrefix,
                        ]);

                        $customer_id = Customer::where('phone_number',$Mobile)->first();

                        Transaction::create([
                            'customer_id' => $customer_id->id,
                            'name'=> $customer->name,
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'phone_number'=> $Mobile,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otpPrefix' => $otpPrefix,
                            'status' => 'pending',
                            'amount' => $Amount->message,
                            'company_id' => $company_id,
                            'description' => 'LoanRepayment'
                        ]);
                        
                        Log::info("response:{$response}");
                        
                        $message = "Enter OTP";
                        $label = "PaymentOTP";
                        $dataType = "text";

                    }
                }
                break;
            case '7':
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if (!empty($otpVerifySubmit)) {

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);

                    Log::info("Response Status: " . $responseOTPVerify->status());
                    Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }
                break;
            }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleLoanRepaymentScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $phoneNumber->message)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $phoneNumber->message){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                $phoneNumberforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1) 
                                ->first();

                $PINforLoan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $phoneNumberforLoan->message)->where('pin', $PINforLoan->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $balance_amount =(!empty($customer_details) && !empty($customer_details->balance)) ? "{$customer_details->balance} GHS" : '0 GHS';
                $loan_details = Loan::where('customer_id', $customer_details->id)->first();

                $message = "Hello " . $customer_details->name . "\nLoan Outstanding Amount: " . $loan_details->remaining_payment . "\n1.Subscription.\n2.Topup.";
                $label = "Customer Details";
                $dataType = "text";
                break;
            // case '5':
            //     $message = "Select Network.\n1.Vodafone.\n2.MTN";
            //     $label = "network";
            //     $dataType = "text";
            //     break;
            case '5':
                $message = "Enter the amount";
                $label = "network";
                $dataType = "text";
                break;
            case '6':
                $PaymentType = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                if($PaymentType->message == '2'){

                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(3)
                                ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(1)
                    //             ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        //"CustomerMsisdn" => '233200777262',
                        "CustomerMsisdn" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'LoanRepayment',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $phoneNumber->message,
                    ]);

                    $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'name' => $customer->name,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumber->message,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];

                } else {
                    $message = "Select Payment Plan.\n1.Daily.\n2.Weekly\n3.Monthly";
                    $label = "plan";
                    $dataType = "text";
                }
                break;
            case '7':
                $PaymentType = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(2)
                            ->first();

                if($PaymentType->message == '2'){
                    // Payment Initiation
                    $message = "Make the payment";
                    $label = "plan";
                    $dataType = "text";
                } else {
                    $PaymentPlan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(2)
                    //             ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone_gh_rec';
                    } else {
                        $Operator = 'mtn_gh_rec';
                    }

                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                    if(empty($otpVerifySubmit)){
                        // handle payment
                        $lastsessionData = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc')
                                    ->first();
                        $payment_system = $lastsessionData->message;
                        if($payment_system == 1){
                            $Pay_Role = "DAILY";
                        } else if($payment_system == 2) {
                            $Pay_Role = "WEEKLY";
                        } else {
                            $Pay_Role = "MONTHLY";
                        }
                        Session::create([
                            'session_id' => $SessionId,
                            'payment_system' => $payment_system,
                        ]);

                        $company_cred = Company::where('company_id', $company_id)->first();
                        $api_id = $company_cred->api_id;
                        $pos_sales_id = $company_cred->pos_sales_id;
                        $api_key = $company_cred->api_key;
                        
                        Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                        $token = base64_encode($api_id.":".$api_key);
                    
                        $response = Http::withHeaders([
                            'Authorization' => "Basic {$token}",
                            'Content-Type' => 'application/json'
                        ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                            "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                            "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                            "description" => 'SusuSavings',
                            "startTime" => now()->addMinutes(5)->format('H:i'),
                            "paymentInterval" => $Pay_Role,
                            "customerMobileNumber" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                            "paymentOption" => "MobileMoney",
                            "channel" => $Operator,
                            "customerName" => $customer->name,
                            "recurringAmount" => $Amount->message,
                            "totalAmount" => $Amount->message,
                            "initialAmount" => $Amount->message,
                            "currency" => "GHS",
                            "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                        ]);

                        Log::info("Response Status: " . $response->status());
                        Log::info("response", ['body' => $response->getBody()->getContents()]);

                        $company_otp = Company::where('company_id', $company_id)->first();

                        $data = json_decode($response, true);
                        if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                            Session::create([
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                            ]);

                            $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                            Transaction::create([
                                'customer_id' => $customer_id->id,
                                'name'=> $customer->name,
                                'session_id' => $SessionId,
                                'phone_number'=> $phoneNumber->message,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'status' => 'pending',
                                'amount' => $Amount->message,
                                'company_id' => $company_id,
                                'description' => 'LoanRepayment'
                            ]);

                            Log::info("response:{$response}");
                            $message = "Please check sms for status of transaction.";
                            $label = "Transaction";
                            $dataType = "display";
                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }
                        $requestId = $data['data']['requestId'];
                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                        $otpPrefix = $data['data']['otpPrefix'];

                        Session::create([
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otp_prefix' => $otpPrefix,
                        ]);

                        $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                        Transaction::create([
                            'customer_id' => $customer_id->id,
                            'name'=> $customer->name,
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'phone_number'=> $phoneNumber->message,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otpPrefix' => $otpPrefix,
                            'status' => 'pending',
                            'amount' => $Amount->message,
                            'company_id' => $company_id,
                            'description' => 'LoanRepayment'
                        ]);
                        
                        Log::info("response:{$response}");
                        
                        $message = "Enter OTP";
                        $label = "PaymentOTP";
                        $dataType = "text";

                    }
                }
                break;
            case '8':
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if (!empty($otpVerifySubmit)) {

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);

                    Log::info("Response Status: " . $responseOTPVerify->status());
                    Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }
                break;
            }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleContactScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";

        $company = Company::where('company_id', $company_id)->first();
        $phone_number = $company->phone_number;
        $location = $company->location;
        $email = $company->email;

        switch ($sequence) {
            case '2':
                $message = "Phone number: " . $phone_number ."\nLocation: " . $location . "\nEmail: " . $email;
                $label = "Contact";
                $dataType = "display";
                break;
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleContactScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";

        $company = Company::where('company_id', $company_id)->first();
        $phone_number = $company->phone_number;
        $location = $company->location;
        $email = $company->email;

        switch ($sequence) {
            case '2':
                $message = "Phone number: " . $phone_number ."\nLocation: " . $location . "\nEmail: " . $email;
                $label = "Contact";
                $dataType = "display";
                break;
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleSusuSavingsScreen($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Select the Payment Plan.\n1.Subscription.\n2.Topup.";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            // case '3':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     break;
            case '3':
                // $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                //             ->whereNotNull('message')
                //             ->whereNotNull('request_json')
                //             ->whereNull('response_json')
                //             ->orderBy('id', 'desc')
                //             ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $Mobile)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $Mobile){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '4':
                // $PhoneNumberForDues = Session::where('session_id', $SessionId)
                //                 ->whereNotNull('message')
                //                 ->whereNotNull('request_json')
                //                 ->whereNull('response_json')
                //                 ->orderBy('id', 'desc') 
                //                 ->skip(1)
                //                 ->first();
                
                $PINForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $Mobile)->where('pin', $PINForDues->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Enter the Amount";
                $label = "amount";
                $dataType = "text";
                break;
            // case '6':
            //     $message = "Select Network.\n1.Vodafone.\n2.MTN";
            //     $label = "network";
            //     $dataType = "text";
            //     break;
            case '5':
                $PaymentType = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                if($PaymentType->message == '2'){

                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(2)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        //"CustomerMsisdn" => '233200777262',
                        "CustomerMsisdn" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'SusuSavings',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $Mobile,
                    ]);

                    $customer_id = Customer::where('phone_number',$Mobile)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'session_id' => $SessionId,
                        'phone_number'=> $Mobile,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];

                } else {
                    $message = "Select Payment Plan.\n1.Daily.\n2.Weekly\n3.Monthly";
                    $label = "plan";
                    $dataType = "text";
                }
                break;
            case '6':
                $PaymentType = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(3)
                            ->first();

                if($PaymentType->message == '2'){
                    // Payment Initiation
                    $message = "Make the payment";
                    $label = "plan";
                    $dataType = "text";
                } else {
                    $PaymentPlan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    // $phoneNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(3)
                    //             ->first();

                    // $OperatorNumber = Session::where('session_id', $SessionId)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc') 
                    //             ->skip(1)
                    //             ->first();

                    $customer = Customer::where('phone_number', $Mobile)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                                
                    if($Operator == 'vodafone'){
                        $Operator = 'vodafone_gh_rec';
                    } else {
                        $Operator = 'mtn_gh_rec';
                    }

                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                    if(empty($otpVerifySubmit)){
                        // handle payment
                        $lastsessionData = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc')
                                    ->first();
                        $payment_system = $lastsessionData->message;
                        if($payment_system == 1){
                            $Pay_Role = "DAILY";
                        } else if($payment_system == 2) {
                            $Pay_Role = "WEEKLY";
                        } else {
                            $Pay_Role = "MONTHLY";
                        }
                        Session::create([
                            'session_id' => $SessionId,
                            'payment_system' => $payment_system,
                        ]);

                        $company_cred = Company::where('company_id', $company_id)->first();
                        $api_id = $company_cred->api_id;
                        $pos_sales_id = $company_cred->pos_sales_id;
                        $api_key = $company_cred->api_key;
                        
                        Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                        $token = base64_encode($api_id.":".$api_key);
                    
                        $response = Http::withHeaders([
                            'Authorization' => "Basic {$token}",
                            'Content-Type' => 'application/json'
                        ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                            "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                            "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                            "description" => 'SusuSavings',
                            "startTime" => now()->addMinutes(5)->format('H:i'),
                            "paymentInterval" => $Pay_Role,
                            "customerMobileNumber" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                            "paymentOption" => "MobileMoney",
                            "channel" => $Operator,
                            "customerName" => $customer->name,
                            "recurringAmount" => $Amount->message,
                            "totalAmount" => $Amount->message,
                            "initialAmount" => $Amount->message,
                            "currency" => "GHS",
                            "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                        ]); 

                        Log::info("Response Status: " . $response->status());
                        Log::info("response", ['body' => $response->getBody()->getContents()]);

                        $company_otp = Company::where('company_id', $company_id)->first();

                        $data = json_decode($response, true);
                        if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                            Session::create([
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                            ]);

                            $customer_id = Customer::where('phone_number',$Mobile)->first();

                            Transaction::create([
                                'customer_id' => $customer_id->id,
                                'name'=> $customer->name,
                                'session_id' => $SessionId,
                                'phone_number'=> $Mobile,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'status' => 'pending',
                                'amount' => $Amount->message,
                                'company_id' => $company_id,
                                'description' => 'SusuSavings'
                            ]);
                            
                            Log::info("response:{$response}");

                            $message = "Please check sms for status of transaction.";
                            $label = "Transaction";
                            $dataType = "display";
                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }
                        $requestId = $data['data']['requestId'];
                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                        $otpPrefix = $data['data']['otpPrefix'];

                        Session::create([
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otp_prefix' => $otpPrefix,
                        ]);

                        $customer_id = Customer::where('phone_number',$Mobile)->first();

                        Transaction::create([
                            'customer_id' => $customer_id->id,
                            'name'=> $customer->name,
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'phone_number'=> $Mobile,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otpPrefix' => $otpPrefix,
                            'status' => 'pending',
                            'amount' => $Amount->message,
                            'company_id' => $company_id,
                            'description' => 'SusuSavings'
                        ]);
                        
                        Log::info("response:{$response}");
                        
                        $message = "Enter OTP";
                        $label = "PaymentOTP";
                        $dataType = "text";

                    }
                }
                break;
            case '7':
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if (!empty($otpVerifySubmit)) {

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);

                    Log::info("Response Status: " . $responseOTPVerify->status());
                    Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleSusuSavingsScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$Operator,$phone_number_check){

        $company = Company::where('company_id', $company_id)->first();
        if ($company && strtolower($company->phone_number_check) === 'yes') {
            if (Str::startsWith($Mobile, '233')) {
                $Mobile = '0' . substr($Mobile, 3);
            }
            $customerExists = DB::table('customers')
                ->where('phone_number', $Mobile)
                ->exists();

            if (!$customerExists) {
                return [
                    "message" => "Please register first to proceed further.",
                    "label" => "Registration Required",
                    "data_type" => "exit",
                    "internal_number" => 0
                ];
            }
        }
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Select the Payment Plan.\n1.Subscription.\n2.Topup.";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '4':
                $phoneNumber = Session::where('session_id', $SessionId)->where('internal_number',5)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc')
                            ->first();

                $company_phone_check = Company::where('company_id', $company_id)->first();
                if($company_phone_check == 'yes'){
                    $phoneExistsInCustomer = Customer::where('phone_number', $phoneNumber->message)->exists();
                    if(!$phoneExistsInCustomer || $Mobile != $phoneNumber->message){
                        $message = "Please Use Your Register Mobile Number";
                        $label = "PhoneNumberNotVerified";
                        $dataType = "display";

                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                }
                $message = "Enter your PIN";
                $label = "PIN";
                $dataType = "text";
                break;
            case '5':
                $PhoneNumberForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                
                $PINForDues = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                $customer_details = Customer::where('phone_number', $PhoneNumberForDues->message)->where('pin', $PINForDues->message)->first();
                if (empty($customer_details)) {
                    $message = "Phone and pin doesn't match!";
                    $label = "PIN";
                    $dataType = "display";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                $message = "Enter the Amount";
                $label = "amount";
                $dataType = "text";
                break;
            case '6':
                $message = "Select Network.\n1.Vodafone.\n2.MTN";
                $label = "network";
                $dataType = "text";
                break;
            case '7':
                $PaymentType = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();
                if($PaymentType->message == '2'){

                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(3)
                                ->first();

                    $OperatorNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();
                                
                    if($OperatorNumber == '1'){
                        $Operator = 'vodafone-gh';
                    } else {
                        $Operator = 'mtn-gh';
                    }

                    $clientReference = Str::random(16);

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                    $token = base64_encode($api_id.":".$api_key);
                
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rmp.hubtel.com/merchantaccount/merchants/'.$pos_sales_id.'/receive/mobilemoney', [
                        "CustomerName" => $customer->name,
                        //"CustomerMsisdn" => '233200777262',
                        "CustomerMsisdn" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                        "Channel" => $Operator,
                        "Amount" => $Amount->message,
                        "PrimaryCallbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback",
                        "Description" => 'SusuSavings',
                        "ClientReference" => $clientReference
                    ]);

                    Log::info("Response Status: " . $response->status());
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $TransactionId = $data['Data']['TransactionId'];
                    $Description = $data['Data']['Description'];
                    $ClientReference = $data['Data']['ClientReference'];
                    $Amount = $data['Data']['Amount'];
                    $Charges = $data['Data']['Charges'];
                    $AmountAfterCharges = $data['Data']['AmountAfterCharges'];
                    $AmountCharged = $data['Data']['AmountCharged'];
                    $DeliveryFee = $data['Data']['DeliveryFee'];

                    Session::create([
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                        'phone_number' => $phoneNumber->message,
                    ]);

                    $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                    Transaction::create([
                        'customer_id' => $customer_id->id,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumber->message,
                        'status' => 'pending',
                        'company_id' => $company_id,
                        'transaction_id' => $TransactionId,
                        'description' => $Description,
                        'client_reference' => $ClientReference,
                        'amount' => $Amount,
                        'charges' => $Charges,
                        'amount_after_charges' => $AmountAfterCharges,
                        'amount_charged' => $AmountCharged,
                        'delivery_fee' => $DeliveryFee,
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Please check sms for status of transaction!";
                    $label = "Transaction";
                    $dataType = "display";

                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];

                } else {
                    $message = "Select Payment Plan.\n1.Daily.\n2.Weekly\n3.Monthly";
                    $label = "plan";
                    $dataType = "text";
                }
                break;
            case '8':
                $PaymentType = Session::where('session_id', $SessionId)
                            ->whereNotNull('message')
                            ->whereNotNull('request_json')
                            ->whereNull('response_json')
                            ->orderBy('id', 'desc') 
                            ->skip(5)
                            ->first();

                if($PaymentType->message == '2'){
                    // Payment Initiation
                    $message = "Make the payment";
                    $label = "plan";
                    $dataType = "text";
                } else {
                    $PaymentPlan = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->first();

                    $phoneNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4)
                                ->first();

                    $OperatorNumber = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(1)
                                ->first();

                    $customer = Customer::where('phone_number', $phoneNumber->message)->first();
                        
                    $Amount = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(2)
                                ->first();
                                
                    if($OperatorNumber == '1'){
                        $Operator = 'vodafone_gh_rec';
                    } else {
                        $Operator = 'mtn_gh_rec';
                    }

                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                    if(empty($otpVerifySubmit)){
                        // handle payment
                        $lastsessionData = Session::where('session_id', $SessionId)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc')
                                    ->first();
                        $payment_system = $lastsessionData->message;
                        if($payment_system == 1){
                            $Pay_Role = "DAILY";
                        } else if($payment_system == 2) {
                            $Pay_Role = "WEEKLY";
                        } else {
                            $Pay_Role = "MONTHLY";
                        }
                        Session::create([
                            'session_id' => $SessionId,
                            'payment_system' => $payment_system,
                        ]);

                        $company_cred = Company::where('company_id', $company_id)->first();
                        $api_id = $company_cred->api_id;
                        $pos_sales_id = $company_cred->pos_sales_id;
                        $api_key = $company_cred->api_key;
                        
                        Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                        $token = base64_encode($api_id.":".$api_key);
                    
                        $response = Http::withHeaders([
                            'Authorization' => "Basic {$token}",
                            'Content-Type' => 'application/json'
                        ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                            "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                            "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                            "description" => 'SusuSavings',
                            "startTime" => now()->addMinutes(5)->format('H:i'),
                            "paymentInterval" => $Pay_Role,
                            "customerMobileNumber" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                            "paymentOption" => "MobileMoney",
                            "channel" => $Operator,
                            "customerName" => $customer->name,
                            "recurringAmount" => $Amount->message,
                            "totalAmount" => $Amount->message,
                            "initialAmount" => $Amount->message,
                            "currency" => "GHS",
                            "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                        ]); 

                        Log::info("Response Status: " . $response->status());
                        Log::info("response", ['body' => $response->getBody()->getContents()]);

                        $company_otp = Company::where('company_id', $company_id)->first();

                        $data = json_decode($response, true);
                        if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                            $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                            Session::create([
                                'session_id' => $SessionId,
                                'recurring_invoice_id' => $recurringInvoiceId,
                            ]);

                            $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                            Transaction::create([
                                'customer_id' => $customer_id->id,
                                'name'=> $customer->name,
                                'session_id' => $SessionId,
                                'phone_number'=> $phoneNumber->message,
                                'recurring_invoice_id' => $recurringInvoiceId,
                                'status' => 'pending',
                                'amount' => $Amount->message,
                                'company_id' => $company_id,
                                'description' => 'SusuSavings'
                            ]);
                            
                            Log::info("response:{$response}");

                            $message = "Please check sms for status of transaction.";
                            $label = "Transaction";
                            $dataType = "display";
                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }
                        $requestId = $data['data']['requestId'];
                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                        $otpPrefix = $data['data']['otpPrefix'];

                        Session::create([
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otp_prefix' => $otpPrefix,
                        ]);

                        $customer_id = Customer::where('phone_number',$phoneNumber->message)->first();

                        Transaction::create([
                            'customer_id' => $customer_id->id,
                            'name'=> $customer->name,
                            'request_id' => $requestId,
                            'session_id' => $SessionId,
                            'phone_number'=> $phoneNumber->message,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'otpPrefix' => $otpPrefix,
                            'status' => 'pending',
                            'amount' => $Amount->message,
                            'company_id' => $company_id,
                            'description' => 'SusuSavings'
                        ]);
                        
                        Log::info("response:{$response}");
                        
                        $message = "Enter OTP";
                        $label = "PaymentOTP";
                        $dataType = "text";

                    }
                }
                break;
            case '9':
                $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                if (!empty($otpVerifySubmit)) {

                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);

                    Log::info("Response Status: " . $responseOTPVerify->status());
                    Log::info("response", ['body' => $responseOTPVerify->getBody()->getContents()]);

                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    } else {
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleAddPackageScreen($SessionId,$sequence,$company_id,$Mobile, $phone_number_check){
        $message = "";
        $label ="";
        $dataType = "";
         Log::info("sequence:{$sequence}");
        switch ($sequence) {
            // case '2':
            //     $message = "Enter your Phone Number";
            //     $label = "PhoneNumber";
            //     $dataType = "text";
            //     break;
            case '2':
                // $phoneNumberforUpdate = Session::where('session_id', $SessionId)
                // ->whereNotNull('message')
                // ->whereNotNull('request_json')
                // ->whereNull('response_json')
                // ->orderBy('id', 'desc')
                // ->first();
                $customer = Customer::where('phone_number', $Mobile)->first();
                if (!empty($customer)) {
                    $message = "Enter your Pin";
                    $label = "Pin";
                    $dataType = "text";
                }else{
                    $message = "No Customer record found with this number!";
                    $label = "No Customer record";
                    $dataType = "display";
                }
                break;
            case '3':
                Log::info("going here");
                $message = "Enter Provider\n1. Vodafone\n2.MTN";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            default:
            Log::info("going here");
                    // $phoneNumberforUpdate = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',3)
                    //             ->whereNotNull('message')
                    //             ->whereNotNull('request_json')
                    //             ->whereNull('response_json')
                    //             ->orderBy('id', 'desc')
                    //             ->first();
                    $PINforupdate = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',4)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->first();
                    $operator = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',5)
                    ->whereNotNull('message')
                    ->whereNotNull('request_json')
                    ->whereNull('response_json')
                    ->orderBy('id', 'desc') 
                    ->first();
                    $customer = Customer::where('phone_number', $Mobile)->where('pin', $PINforupdate->message)->first();
                    if (empty($customer)) {
                        $message = "No Customer record found with this number!";
                        $label = "No Customer record";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    $operator_value = $operator->message;
                    if($operator_value == "1"){
                        $Operator = "vodafone_gh_rec";
                    } else {
                        $Operator = "mtn_gh_rec";
                    }
                    Customer::where('phone_number', $Mobile)->update(['operator_channel'=> $Operator]);
                    // $customer = Customer::where('phone_number', $phoneNumberforUpdate->message)->where('pin', $PINforupdate->message)->first();
                    // if (!empty($customer->reset_pin) && $customer->reset_pin == 1 ) {
                    //     $message = "Please do one time setup up from register to get your details!";
                    //     $label = "PIN";
                    //     $dataType = "display";
                    //     return [
                    //         "message" => $message,
                    //         "label"=>$label,
                    //         "data_type"=>$dataType
                    //     ];
                    // }
                    $plan = Plan::where('plan_id', $customer->plan_id)->first();

                    $session = Session::where('session_id', $SessionId)->first();
                    $start = $session ? $session->packages_start_index : 0;
                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('selected_plan_id')
                                  ->first();
                $selected_plan_id = $lastsessionData ? $lastsessionData->selected_plan_id : null; 
                if($selected_plan_id != '' && empty($otpVerifySubmit)){
                    // handle payment
                    $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('message')
                                  ->whereNotNull('request_json')
                                  ->whereNull('response_json')
                                  ->orderBy('id', 'desc')
                                  ->first();
                    $payment_system = $lastsessionData->message;
                    $selectedPlanWithPaymentSystem = Plan::where('plan_id', $selected_plan_id)->first();
                    $plan_name = $selectedPlanWithPaymentSystem->name;
                    if($payment_system == 1){
                        $Pay_Role = "DAILY";
                        $pay_price = $selectedPlanWithPaymentSystem->daily;
                    } else if($payment_system == 2) {
                        $Pay_Role = "WEEKLY";
                        $pay_price = $selectedPlanWithPaymentSystem->weekly;
                    } else {
                        $Pay_Role = "MONTHLY";
                        $pay_price = $selectedPlanWithPaymentSystem->monthly;
                    }
                    $pay_price =floatval($pay_price);
                    Session::create([
                        'selected_plan_id' => $selected_plan_id,
                        'session_id' => $SessionId,
                        'payment_system' => $payment_system,
                    ]);
                    
                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$Mobile}");
                    $token = base64_encode($api_id.":".$api_key);
                   
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                        "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                        "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                        "description" => $plan_name,
                        "startTime" => now()->addMinutes(5)->format('H:i'),
                        "paymentInterval" => $Pay_Role,
                        "customerMobileNumber" => strpos($Mobile, '0') === 0 ? intval('233' . substr($Mobile, 1)) : intval($Mobile),
                        "paymentOption" => "MobileMoney",
                        "channel" => $Operator,
                        "customerName" => $customer->name,
                        "recurringAmount" => $pay_price,
                        "totalAmount" => $pay_price,
                        "initialAmount" => $pay_price,
                        "currency" => "GHS",
                        "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                    ]);
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $company_otp = Company::where('company_id', $company_id)->first();

                    $data = json_decode($response, true);
                    if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                        Session::create([
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                        ]);

                        Transaction::create([
                            'name'=> $plan_name,
                            'session_id' => $SessionId,
                            'phone_number'=> $Mobile,
                            'selected_plan_id'=>$selected_plan_id,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'status' => 'pending',
                            'amount' => $pay_price,
                            'company_id' => $company_id,
                            'description' => $plan_name
                        ]); 
                        
                        Log::info("response:{$response}");

                        $message = "Please check sms for status of transaction.";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    $requestId = $data['data']['requestId'];
                    $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                    $otpPrefix = $data['data']['otpPrefix'];

                    Session::create([
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otp_prefix' => $otpPrefix,
                    ]);

                    Transaction::create([
                        'name'=> $plan_name,
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'phone_number'=> $Mobile,
                        'selected_plan_id'=>$selected_plan_id,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                        'status' => 'pending',
                        'amount' => $pay_price,
                        'company_id' => $company_id,
                        'description' => $plan_name
                    ]); 
                    
                    Log::info("response:{$response}");
                    
                    $message = "Enter OTP";
                    $label = "PaymentOTP";
                    $dataType = "text";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                if (!empty($otpVerifySubmit)) {
                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);
                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }else{
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }else{
                        return $this->handlePackageNavigationForAddNewPackage($SessionId,$start,$plan,$sequence);
                    
                }
                    
                break;
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleAddPackageScreenNoPhone($SessionId,$sequence,$company_id,$Mobile,$phone_number_check){
        $message = "";
        $label ="";
        $dataType = "";
         Log::info("sequence:{$sequence}");
        switch ($sequence) {
            case '2':
                $message = "Enter your Phone Number";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            case '3':
                $phoneNumberforUpdate = Session::where('session_id', $SessionId)
                ->whereNotNull('message')
                ->whereNotNull('request_json')
                ->whereNull('response_json')
                ->orderBy('id', 'desc')
                ->first();
                $customer = Customer::where('phone_number', $phoneNumberforUpdate->message)->first();
                if (!empty($customer)) {
                    $message = "Enter your Pin";
                    $label = "Pin";
                    $dataType = "text";
                }else{
                    $message = "No Customer record found with this number!";
                    $label = "No Customer record";
                    $dataType = "display";
                }
                break;
            case '4':
                Log::info("going here");
                $message = "Enter Provider\n1. Vodafone\n2.MTN";
                $label = "PhoneNumber";
                $dataType = "text";
                break;
            default:
            Log::info("going here");
                    $phoneNumberforUpdate = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',3)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc')
                                ->first();
                    $PINforupdate = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',4)
                                    ->whereNotNull('message')
                                    ->whereNotNull('request_json')
                                    ->whereNull('response_json')
                                    ->orderBy('id', 'desc') 
                                    ->first();
                    $operator = Session::where('session_id', $SessionId)->where('casetype','addPackage')->where('sequence',5)
                    ->whereNotNull('message')
                    ->whereNotNull('request_json')
                    ->whereNull('response_json')
                    ->orderBy('id', 'desc') 
                    ->first();
                    $customer = Customer::where('phone_number', $phoneNumberforUpdate->message)->where('pin', $PINforupdate->message)->first();
                    if (empty($customer)) {
                        $message = "No Customer record found with this number!";
                        $label = "No Customer record";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    $operator_value = $operator->message;
                    if($operator_value == "1"){
                        $Operator = "vodafone_gh_rec";
                    } else {
                        $Operator = "mtn_gh_rec";
                    }
                    Customer::where('phone_number', $phoneNumberforUpdate->message)->update(['operator_channel'=> $Operator]);
                    // $customer = Customer::where('phone_number', $phoneNumberforUpdate->message)->where('pin', $PINforupdate->message)->first();
                    // if (!empty($customer->reset_pin) && $customer->reset_pin == 1 ) {
                    //     $message = "Please do one time setup up from register to get your details!";
                    //     $label = "PIN";
                    //     $dataType = "display";
                    //     return [
                    //         "message" => $message,
                    //         "label"=>$label,
                    //         "data_type"=>$dataType
                    //     ];
                    // }
                    $plan = Plan::where('plan_id', $customer->plan_id)->first();

                    $session = Session::where('session_id', $SessionId)->first();
                    $start = $session ? $session->packages_start_index : 0;
                    $otpVerifySubmit = Session::where('session_id', $SessionId)->whereNotNull('recurring_invoice_id')->orderBy('id', 'desc')->first();

                $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('selected_plan_id')
                                  ->first();
                $selected_plan_id = $lastsessionData ? $lastsessionData->selected_plan_id : null; 
                if($selected_plan_id != '' && empty($otpVerifySubmit)){
                    // handle payment
                    $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('message')
                                  ->whereNotNull('request_json')
                                  ->whereNull('response_json')
                                  ->orderBy('id', 'desc')
                                  ->first();
                    $payment_system = $lastsessionData->message;
                    $selectedPlanWithPaymentSystem = Plan::where('plan_id', $selected_plan_id)->first();
                    $plan_name = $selectedPlanWithPaymentSystem->name;
                    if($payment_system == 1){
                        $Pay_Role = "DAILY";
                        $pay_price = $selectedPlanWithPaymentSystem->daily;
                    } else if($payment_system == 2) {
                        $Pay_Role = "WEEKLY";
                        $pay_price = $selectedPlanWithPaymentSystem->weekly;
                    } else {
                        $Pay_Role = "MONTHLY";
                        $pay_price = $selectedPlanWithPaymentSystem->monthly;
                    }
                    $pay_price =floatval($pay_price);
                    Session::create([
                        'selected_plan_id' => $selected_plan_id,
                        'session_id' => $SessionId,
                        'payment_system' => $payment_system,
                    ]);
                    
                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    Log::info("Payment Initiated sessionID:{$SessionId} with phone number:{$phoneNumber->message}");
                    $token = base64_encode($api_id.":".$api_key);
                   
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/'.$pos_sales_id.'/create-invoice', [
                        "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                        "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                        "description" => $plan_name,
                        "startTime" => now()->addMinutes(5)->format('H:i'),
                        "paymentInterval" => $Pay_Role,
                        "customerMobileNumber" => strpos($phoneNumberforUpdate->message, '0') === 0 ? intval('233' . substr($phoneNumberforUpdate->message, 1)) : intval($phoneNumberforUpdate->message),
                        "paymentOption" => "MobileMoney",
                        "channel" => $Operator,
                        "customerName" => $customer->name,
                        "recurringAmount" => $pay_price,
                        "totalAmount" => $pay_price,
                        "initialAmount" => $pay_price,
                        "currency" => "GHS",
                        "callbackUrl" => "https://ussd.atdamss.com/api/$company_id/ussd/callback"
                    ]);
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $company_otp = Company::where('company_id', $company_id)->first();

                    $data = json_decode($response, true);
                    if(!isset($data['data']['requestId']) || !isset($data['data']['otpPrefix']) || $company_otp->otp_check == 'no'){

                        $recurringInvoiceId = $data['data']['recurringInvoiceId'];

                        Session::create([
                            'session_id' => $SessionId,
                            'recurring_invoice_id' => $recurringInvoiceId,
                        ]);

                        Transaction::create([
                            'name'=> $plan_name,
                            'session_id' => $SessionId,
                            'phone_number'=> $phoneNumberforUpdate->message,
                            'selected_plan_id'=>$selected_plan_id,
                            'recurring_invoice_id' => $recurringInvoiceId,
                            'status' => 'pending',
                            'amount' => $pay_price,
                            'company_id' => $company_id,
                            'description' => $plan_name
                        ]); 
                        
                        Log::info("response:{$response}");

                        $message = "Please check sms for status of transaction.";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    $requestId = $data['data']['requestId'];
                    $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                    $otpPrefix = $data['data']['otpPrefix'];

                    Session::create([
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otp_prefix' => $otpPrefix,
                    ]);

                    Transaction::create([
                        'name'=> $plan_name,
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumberforUpdate->message,
                        'selected_plan_id'=>$selected_plan_id,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                        'status' => 'pending',
                        'amount' => $pay_price,
                        'company_id' => $company_id,
                        'description' => $plan_name
                    ]); 
                    
                    Log::info("response:{$response}");
                    
                    $message = "Enter OTP";
                    $label = "PaymentOTP";
                    $dataType = "text";
                    return [
                        "message" => $message,
                        "label"=>$label,
                        "data_type"=>$dataType
                    ];
                }
                if (!empty($otpVerifySubmit)) {
                    $company_cred = Company::where('company_id', $company_id)->first();
                    $api_id = $company_cred->api_id;
                    $pos_sales_id = $company_cred->pos_sales_id;
                    $api_key = $company_cred->api_key;

                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode($api_id.":".$api_key);
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otp_prefix}-{$lastOTPsession->message}"
                    ]);
                    $responseOTPVerifyData = json_decode($responseOTPVerify, true);
                    if (!empty($responseOTPVerifyData) && !empty($responseOTPVerifyData['responseCode']) && $responseOTPVerifyData['responseCode'] =="0001") {
                        $recurringInvoiceId = $responseOTPVerifyData['data']['recurringInvoiceId'];
                        Transaction::where('recurring_invoice_id',$recurringInvoiceId)->update(['status'=>'authenticated']);
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }else{
                        $message = "Please check sms for status of transaction!";
                        $label = "Transaction";
                        $dataType = "display";
                        return [
                            "message" => $message,
                            "label"=>$label,
                            "data_type"=>$dataType
                        ];
                    }
                    
                }else{
                        return $this->handlePackageNavigationForAddNewPackage($SessionId,$start,$plan,$sequence);
                    
                }
                    
                break;
        }
        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }
    
    public function handlePackageNavigation($SessionId,$start,$company_id) {

        $perPage = setting('admin.plans_per_page') ?? 6;
        
        $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('message')
                                  ->whereNull('response_json')
                                  ->orderBy('id', 'desc')
                                  ->first();
        $userInput = $lastsessionData->message;

        if ($userInput !== '#' && $userInput !== '0') {
            $planDetails = Plan::where('plan_id', $userInput)->first();
    
            if ($planDetails) {

                Session::create([
                    'selected_plan_id' => $planDetails->plan_id,
                    'session_id' => $SessionId
                ]);
                
                return [
                    "message" => $planDetails->name 
                                . "\nPrice: " . $planDetails->price 
                                . "\n1. Daily: " . $planDetails->daily 
                                . "\n2. Weekly: " . $planDetails->weekly 
                                . "\n3. Monthly: " . $planDetails->monthly,
                    "label" => "PaymentType",
                    "data_type" => "text"
                ];
            }
        }
        if ($userInput == '#') {
            $start += $perPage; 
        } elseif ($userInput == '0') {
            $start = max(0, $start - $perPage); 
        }

        Session::where('session_id', $SessionId)
                ->orderBy('id', 'desc')
                ->limit(2)
                ->update(['packages_start_index' => $start]);
        
        $company = Company::where('company_id', $company_id)->first();
        $companyId = $company->id;
        $plans = Plan::where('company_id', $companyId)
                     ->orderByRaw('CAST(plan_sequence AS UNSIGNED) ASC')
                     ->skip($start)
                     ->take($perPage)
                     ->get();
    
        $packages = "Choose your plan:";
        foreach ($plans as $plan) {
            $packages .= "\n" . $plan->plan_id . ". " . $plan->name;
        }
    
        $totalPlans = Plan::count();
        if ($start > 0) {
            $packages .= "\n0. Show me previous packages";
        }
        if ($start + $perPage < $totalPlans) {
            $packages .= "\n#. Show me next packages";
        }

        return [
            "message" => $packages,
            "label" => "Packages",
            "data_type" => "text"
        ];
    }

    public function handlePackageNavigationForAddNewPackage($SessionId,$start,$plan,$sequence) {
        $perPage = setting('admin.plans_per_page') ?? 6;
        
        $lastsessionData = Session::where('session_id', $SessionId)
                                  ->whereNotNull('message')
                                  ->whereNull('response_json')
                                  ->orderBy('id', 'desc')
                                  ->first();
        $userInput = $lastsessionData->message;

        if ($userInput !== '#' && $userInput !== '0' && $sequence != "5") {
            $planDetails = Plan::where('plan_id', $userInput)->first();
    
            if ($planDetails) {

                Session::create([
                    'selected_plan_id' => $planDetails->plan_id,
                    'session_id' => $SessionId,
                ]);
                
                return [
                    "message" => $planDetails->name 
                                . "\nPrice: " . $planDetails->price 
                                . "\n1. Daily: " . $planDetails->daily 
                                . "\n2. Weekly: " . $planDetails->weekly 
                                . "\n3. Monthly: " . $planDetails->monthly,
                    "label" => "PaymentType",
                    "data_type" => "text"
                ];
            }
        }
        
        if ($userInput == '#') {
            $start += $perPage;  
        } elseif ($userInput == '0') {
            $start = max(0, $start - $perPage);  
        }

        Session::where('session_id', $SessionId)->update(['packages_start_index' => $start]);
        
        $company = Company::where('company_id', $company_id)->first();
        $companyId = $company->id;
        $plans = Plan::where('company_id', $companyId)
                     ->orderByRaw('CAST(plan_sequence AS UNSIGNED) ASC')
                     ->skip($start)
                     ->take($perPage)
                     ->get();
        if(isset($plan->name) && !empty($plan->name)){
            $packages = "Choose your new plan:";
        } else {
            $packages = "Choose your new plan:";
        }
        foreach ($plans as $plan) {
            $packages .= "\n" . $plan->plan_id . ". " . $plan->name;
        }
    
        $totalPlans = Plan::count();
        if ($start > 0) {
            $packages .= "\n0. Show me previous packages";
        }
        if ($start + $perPage < $totalPlans) {
            $packages .= "\n#. Show me next packages";
        }

        return [
            "message" => $packages,
            "label" => "Packages",
            "data_type" => "text"
        ];
    }

}