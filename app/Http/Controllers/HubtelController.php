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
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubtelController extends Controller
{
    public function hubtelUSSD(Request $request){
        $caseType = null;
        if($request->Type == 'Response'){
            if ($request->Sequence == 2) {
                switch ($request->Message) {
                    case '1':
                        $caseType = 'register';
                        break;
                    case '2':
                        $caseType = 'checkbalance';
                        break;
                    case '3':
                        $caseType = 'checkdetails';
                        break;
                    case '4':
                        $caseType = 'contact';
                        break;
                    case '5':
                        $caseType = 'update';
                        break;
                    case '6':
                        $caseType = 'addPackage';
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

        $responseTypeData = $this->handleType($request->Type,$request->Message,$request->Sequence,$request->SessionId,$caseType,$request->ServiceCode);
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

    public function hubtelUSSDCallback(Request $request)
    {
        CallbackRequest::create(['request' => json_encode($request->all())]);
        $lastTransaction = Transaction::where('recurring_invoice_id',$request->Data['RecurringInvoiceId'])->orderBy('created_at','DESC')->first();
        if ($request->Message=="Success" && $request->ResponseCode == "0000") {
            $plan_id = $lastTransaction->selected_plan_id;
            $phone_number = $lastTransaction->phone_number;
            if ($lastTransaction->status != "pending" && !empty($lastTransaction->cancel_plan_id)) {
               $cancelOldRecurring = Transaction::where('phone_number',$phone_number)->where('selected_plan_id',$lastTransaction->cancel_plan_id)->whereNotNull('recurring_invoice_id')->first();
               if (!empty($cancelOldRecurring) && !empty($cancelOldRecurring->recurring_invoice_id)) {
                $token = base64_encode("lRk35Zg:221d0bb469cb4a9da90c198190db640a");
                $response = Http::withHeaders([
                    'Authorization' => "Basic {$token}",
                    'Content-Type' => 'application/json'
                ])->delete("https://rip.hubtel.com/api/proxy/2023714/cancel-invoice/{$cancelOldRecurring->recurring_invoice_id}", [
                    "callbackUrl" => "https://admin.smido.org/api/ussd/callback"
                ]);
                $responseDataCancel = $response->getBody()->getContents();
                Log::info("Plan cancelled request{$responseDataCancel}");
               }
               if (!empty($cancelOldRecurring)) {
                Subscription::where('phone_number',$phone_number)->where('plan_id',$lastTransaction->cancel_plan_id)->update([
                    'status' => "cancelled"
                ]);
               }
            }
            if (strpos($phone_number, '233') === 0) {
                $phone_number = '0' . substr($phone_number, 3);
            }
            Transaction::where('recurring_invoice_id',$request->Data['RecurringInvoiceId'])->where('status','authenticated')->delete();
            Transaction::create(['name'=>$request->Data['Description'],'recurring_invoice_id'=>$request->Data['RecurringInvoiceId'],'amount'=>$request->Data['RecurringAmount'],'selected_plan_id'=>$plan_id,'status'=>'success','phone_number'=>$lastTransaction->phone_number,'datetime'=>Carbon::now()]);
            Customer::where('phone_number',$phone_number)->update([
                // 'packages_start_index' => 0,
                'plan_id' => $plan_id
            ]);
        }

    }

    public function handleType($type,$inputmessage,$sequence,$SessionId,$caseType,$ServiceCode){
        $message = "";
        $label ="";
        $dataType = "";

        $sessionData = Session::where('session_id', $SessionId)
                    ->where('sequence', 2)
                    ->whereNotNull('message')
                    ->whereNotNull('request_json')
                    ->whereNull('response_json')
                    ->orderBy('id', 'desc') 
                    ->first();
        
        $company = Company::where('service_code', $ServiceCode)->first();

        switch ($type) {
            case 'Initiation':
                $message = "Welcome to ".$company->name.".\nWhat do you want to do:\n1. Register\n2. Check balance\n3. Check details\n4. Contact Us\n5. Update\n6. Add Package";
                $label = "Welcome";
                $dataType = "input";
                break;
            case 'Response':
                switch ($caseType) {
                    case 'register':
                        $RegisterScreen = $this->handleRegisterScreen($SessionId,$sequence);
                        $message = $RegisterScreen['message'];
                        $label = $RegisterScreen['label'];
                        $dataType = $RegisterScreen['data_type'];
                        $internal_number = !empty($RegisterScreen['internal_number']) ? $RegisterScreen['internal_number']:0;
                        break;
                    case 'checkbalance':
                        $checkBalanceScreen = $this->handleCheckBalanceScreen($SessionId,$sequence);
                        $message = $checkBalanceScreen['message'];
                        $label = $checkBalanceScreen['label'];
                        $dataType = $checkBalanceScreen['data_type'];
                        break;
                    case 'makepayment':
                        $makePaymentScreen = $this->handleMakePaymentScreen($SessionId,$sequence);
                        $message = $makePaymentScreen['message'];
                        $label = $makePaymentScreen['label'];
                        $dataType = $makePaymentScreen['data_type'];
                        break;
                    case 'contact':
                        $ContactScreen = $this->handleContactScreen($SessionId,$sequence);
                        $message = $ContactScreen['message'];
                        $label = $ContactScreen['label'];
                        $dataType = $ContactScreen['data_type'];
                        break;
                    case 'loan':
                        $LoanScreen = $this->handleLoanScreen($SessionId,$sequence);
                        $message = $LoanScreen['message'];
                        $label = $LoanScreen['label'];
                        $dataType = $LoanScreen['data_type'];
                        break;
                    case 'withdrawl':
                        $WithdrawlScreen = $this->handleWithdrawlScreen($SessionId,$sequence);
                        $message = $WithdrawlScreen['message'];
                        $label = $WithdrawlScreen['label'];
                        $dataType = $WithdrawlScreen['data_type'];
                        break;
                    case 'susu_savings':
                        $SusuSavingsScreen = $this->handleSusuSavingsScreen($SessionId,$sequence);
                        $message = $SusuSavingsScreen['message'];
                        $label = $SusuSavingsScreen['label'];
                        $dataType = $SusuSavingsScreen['data_type'];
                        break;
                    case 'addPackage':
                        $UpdateScreen = $this->handleAddPackageScreen($SessionId,$sequence);
                        $message = $UpdateScreen['message'];
                        $label = $UpdateScreen['label'];
                        $dataType = $UpdateScreen['data_type'];
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

    public function handleRegisterScreen($SessionId,$sequence){
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
                $message = "Enter Provider\n1. Vodafone\n2.MTN";
                $label = "PhoneNumber";
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
            default:
            if ($sequence >= 7) {
                $internal_number=7;
                    $sessionData = Session::where('session_id', $SessionId)
                                ->whereNotNull('message')
                                ->whereNotNull('request_json')
                                ->whereNull('response_json')
                                ->orderBy('id', 'desc') 
                                ->skip(4) 
                                ->first();                    

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
                    $customerInfo = Customer::where('phone_number', $phoneNumber->message)->first();
                    $operator_value = $provider->message;
                    if($operator_value == "1"){
                        $Operator = "vodafone_gh_rec";
                    } else {
                        $Operator = "mtn_gh_rec";
                    }
                    if (empty($customerInfo) && $sequence ==7) {
                        Customer::create([
                            'name' => $firstName->message . " " . $lastName->message,
                            'phone_number' => $phoneNumber->message,
                            'pin' => $PIN->message,
                            'operator_channel'=> $Operator
                        ]);
                    }else{
                        if ($sequence ==7) {
                            $message = "Customer already exists with this phone number!";
                            $label = "Customer";
                            $dataType = "display";
                            return [
                                "message" => $message,
                                "label"=>$label,
                                "data_type"=>$dataType
                            ];
                        }

                        
                    }


                    Session::where('session_id', $SessionId)->update([
                        // 'packages_start_index' => 0,
                        'package_selection' => true
                    ]);
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
                    
                    Log::info("Payment Initiated sessionID:{$SessionId} and planID:{$selected_plan_id} with payment system:{$payment_system}");
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                   
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/2023714/create-invoice', [
                        "orderDate" => now()->addDay()->format('Y-m-d\TH:i:s'),
                        "invoiceEndDate" => now()->addYear()->format('Y-m-d\TH:i:s'),
                        "description" => $plan_name,
                        "startTime" => now()->addMinutes(5)->format('H:i'),
                        "paymentInterval" => $Pay_Role,
                        "customerMobileNumber" => strpos($phoneNumber->message, '0') === 0 ? intval('233' . substr($phoneNumber->message, 1)) : intval($phoneNumber->message),
                        "paymentOption" => "MobileMoney",
                        "channel" => $Operator,
                        "customerName" => $firstName->message . " " . $lastName->message,
                        "recurringAmount" => $pay_price,
                        "totalAmount" => $pay_price,
                        "initialAmount" => $pay_price,
                        "currency" => "GHS",
                        "callbackUrl" => "https://admin.smido.org/api/ussd/callback"
                    ]);
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $requestId = $data['data']['requestId'];
                    $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                    $otpPrefix = $data['data']['otpPrefix'];

                    Session::create([
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                    ]);

                    Transaction::create([
                        'name'=> $plan_name,
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'phone_number'=> $phoneNumber->message,
                        'selected_plan_id'=>$selected_plan_id,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
                        'status' => 'pending',
                        'amount' => $pay_price
                    ]);
                    
                    Log::info("response:{$response}");
                    
                    $message = "Enter OTP";
                    $label = "PaymentOTP";
                    $dataType = "text";
                    break;

                }
                if (!empty($otpVerifySubmit)) {
                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otpPrefix}-{$lastOTPsession->message}"
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
                    // $lastsessionData = Session::where('session_id', $SessionId)
                    // ->whereNotNull('recurring_invoice_id')
                    // ->whereNotNull('request_id')
                    // ->whereNotNull('otpPrefix')
                    // ->first();
                    // $selected_plan_id = $lastsessionData ? $lastsessionData->selected_plan_id : null; 

                    $session = Session::where('session_id', $SessionId)->orderBy('id', 'desc')->skip(2)->first();
                    $start = $session->packages_start_index ? $session->packages_start_index : 0;
                    
                    return $this->handlePackageNavigation($SessionId,$start);
                }

            }
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
            "callbackUrl" => "https://admin.smido.org/api/ussd/callback"
        ]);
        dd($response->getBody()->getContents());
    }

    public function handleCheckBalanceScreen($SessionId,$sequence){
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
                // if (!empty($balance->reset_pin) && $balance->reset_pin == 1 ) {
                //     $message = "Please do one time setup up from register to get your details!";
                //     $label = "PIN";
                //     $dataType = "display";
                //     return [
                //         "message" => $message,
                //         "label"=>$label,
                //         "data_type"=>$dataType
                //     ];
                // }
                $balance_amount =(!empty($balance) && !empty($balance->balance)) ? "{$balance->balance} GHS" : '0 GHS';
                $message = "Name: {$balance->name}\nPhone Number: {$balance->phone_number}\nBalance: ". $balance_amount;
                $label = "PIN";
                $dataType = "text";
                break;
        }

        return [
            "message" => $message,
            "label"=>$label,
            "data_type"=>$dataType
        ];
    }

    public function handleContactScreen($SessionId,$sequence){
        $message = "";
        $label ="";
        $dataType = "";
        switch ($sequence) {
            case '2':
                $message = "Phone number:  0595813400\n 0595813400 \n P.o. Box 7663, First floor ITTU building, Suame Magazine Kumasi. \nEmail: Info@smido.org";
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

    public function handleAddPackageScreen($SessionId,$sequence){
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
                    
                    Log::info("Payment Initiated sessionID:{$SessionId} and planID:{$selected_plan_id} with payment system:{$payment_system}");
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                   
                    $response = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/2023714/create-invoice', [
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
                        "callbackUrl" => "https://admin.smido.org/api/ussd/callback"
                    ]);
                    Log::info("response", ['body' => $response->getBody()->getContents()]);

                    $data = json_decode($response, true);
                    $requestId = $data['data']['requestId'];
                    $recurringInvoiceId = $data['data']['recurringInvoiceId'];
                    $otpPrefix = $data['data']['otpPrefix'];

                    Session::create([
                        'request_id' => $requestId,
                        'session_id' => $SessionId,
                        'recurring_invoice_id' => $recurringInvoiceId,
                        'otpPrefix' => $otpPrefix,
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
                        'amount' => $pay_price
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
                    $lastOTPsession = Session::where('session_id', $SessionId)->orderBy('created_at','DESC')->first();
                    $token = base64_encode("4Yo3kGV:d2291feeeea0419f8f9e907caeceb7d3");
                    $responseOTPVerify = Http::withHeaders([
                        'Authorization' => "Basic {$token}",
                        'Content-Type' => 'application/json'
                    ])->post('https://rip.hubtel.com/api/proxy/verify-invoice', [
                        "recurringInvoiceId" => $otpVerifySubmit->recurring_invoice_id,
                        "requestId" => $otpVerifySubmit->request_id,
                        "otpCode" => "{$otpVerifySubmit->otpPrefix}-{$lastOTPsession->message}"
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

    public function handlePackageNavigation($SessionId,$start) {
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
        // dd($start);

        Session::where('session_id', $SessionId)
                ->orderBy('id', 'desc')
                ->limit(2)
                ->update(['packages_start_index' => $start]);
        
        $plans = Plan::orderByRaw('CAST(plan_sequence AS UNSIGNED) ASC')
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
        
        $plans = Plan::orderByRaw('CAST(plan_sequence AS UNSIGNED) ASC')
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

