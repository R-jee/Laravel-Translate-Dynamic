<?php

namespace App\Http\Controllers;

use App\Exports\ListsExports;
use App\Jobs\AsyncHttpRequestJob;
use App\Models\Affiliate;
use App\Models\ApplicationCommunication;
use App\Models\NewStoreApplication;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\CompanyEmailTemplates;
use App\Models\CompanySmsTemplates;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use App\Helper\SendSmsMessage;

class ApplicationFormController extends Controller
{
    /**
     * @var EditApplicationFormController
     */
    private $editApplicationFormController;
    private $sendSmsMessage;


    public function __construct(
        EditApplicationFormController $editApplicationFormController,
        SendSmsMessage                $sendSmsMessage
    )
    {
        $this->editApplicationFormController = $editApplicationFormController;
        $this->sendSmsMessage = $sendSmsMessage;
    }

    /**
     * This function will be called while filtering records
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function filterRecords(Request $request): View
    {
        if ($this->redirectUser($request)) {
            return redirect()->back();
        }
        $applications = $this->searchFilter($request);
        $filePath = storage_path('app/temp/application_search.xlsx');
        Excel::store(new ListsExports($applications), 'temp/application_search.xlsx');
        if ($filePath) {
            return view("biolink.applications.list", compact('applications', 'filePath'));
        }
        return view("biolink.applications.list", compact('applications'));
    }

    public function searchFilter($request)
    {
        $tenant = $request->session()->get('sessCompanyContext') ?? null;
        $tenantId = '';
        if (isset($tenant->id) && $tenant->is_enabled == 1) {
            $tenantId = $tenant->id;
        }

        $startDate = $request->start_date;
        $endDate = $request->end_date;
        $name = $request->filter_name;
        $phone = $request->filter_phone;
        $status = $request->status;
        $query = NewStoreApplication::query();
        if ($startDate) {
            $query->whereDate('created_at', '>=', $startDate);
            $request->flash('start_date', $startDate);
        }
        if ($endDate) {
            $query->whereDate('created_at', '<=', $endDate);
            $request->flash('end_date', $endDate);
        }
        if ($name) {
            $query->where('applicant_name', 'like', '%' . $name . '%');
            $request->flash('filter_name', $name);
        }
        if ($phone) {
            $query->where('applicant_phone', 'like', '%' . $phone . '%');
            $request->flash('filter_phone', $phone);
        }
        if (isset($status)) {
            $query->where('status', '=', $status);
            $request->flash('status', $status);
        }
        if ($tenantId) {
            $query = $query->where('tenant_id', $tenantId);
        }
        $applications = $query->orderBy('id', 'asc')->paginate(10);
        return $applications;
    }

    /**
     * This function will be called while download the Excel file.
     *
     * @return \Maatwebsite\Excel\Facades\Excel
     */
    public function exportLists(Request $request)
    {
        $filePath = $request->input('filePath');
        if ($filePath) {
            return new BinaryFileResponse($filePath);
        } else {
            $data = NewStoreApplication::all();
            return Excel::download(new ListsExports ($data), 'lists.xlsx');
        }
    }

    /**
     * This function will be called in order to delete the application form.
     *
     * @param $id
     * @return RedirectResponse
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function deleteApplication($id): RedirectResponse
    {
        $this->authorize('destroy new_store_applications');
        $application = NewStoreApplication::find($id);
        $application->delete();
        return back()->with('success_message', __('Application Deleted Successfully'));
    }

    /**
     * This function will be called while submit the new store application form
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function frApplicationSubmit(Request $request)
    {
        $inputs = $request->input('inputs');
        $jsonField = [];
        if (isset($inputs)) {
            foreach ($inputs as $input) {
                $extraField = isset($input['extra_field']) ? $input['extra_field'] : null;
                $extraFieldLabel = isset($input['extra_field_label']) ? $input['extra_field_label'] : null;
                $jsonField[] = json_encode(['extra_field' => $extraField, 'extra_field_label' => $extraFieldLabel]);
            }
        }
        //Validate the submitted parameters with database
        if ($request->has_store_experience == 1) {
            $request->validate([
                'tenant_id' => 'required',
                'applicant_name' => 'required',
                'applicant_phone' => '',
                'applicant_email' => 'required|email|unique:new_store_applications',
                'has_store_owner_experience' => 'required',
                'store_name' => 'required',
                'store_owner_experience' => 'required',
                'employee_experience' => 'required',
                'city' => 'required',
                'address' => '',
            ]);
        } else {
            $request->validate([
                'tenant_id' => 'required',
                'applicant_name' => 'required',
                'applicant_phone' => '',
                'city' => '',
                'address' => '',
            ]);
        }
        //Submit data to the database
        $tenantId = Crypt::decryptString($request->tenant_id);
        $applicationForm = new NewStoreApplication;
        $inputs = $request->input('inputs');
        $applicationForm->tenant_id = $tenantId;
        $applicationForm->applicant_name = $request->applicant_name;
        $applicationForm->applicant_phone = $request->applicant_phone;
        $applicationForm->applicant_email = $request->applicant_email;
        $applicationForm->has_store_owner_experience = $request->has_store_owner_experience ?? null;
        $applicationForm->store_name = $request->store_name;
        $applicationForm->store_owner_experience = $request->store_owner_experience;
        $applicationForm->employee_experience = $request->employee_experience;
        $applicationForm->city = $request->city ?? "";
        $applicationForm->address = $request->address ?? "";
        $applicationForm->store_ownership = $request->store_ownership ?? "";
        $applicationForm->message = $request->message ?? "";
        $applicationForm->terms = $request->terms;
        $applicationForm->extra_information = json_encode($jsonField);
        $applicationForm->save();
        //Send SMS
        $smsTemplate = CompanySmsTemplates::where(['tenant_id' => $tenantId, 'type' => 1])->first();
        if ($smsTemplate && $smsTemplate->automatically_send) {
            $content = $smsTemplate->content ?? "";
            $sms = $this->sendSmsMessage->smsSend($applicationForm->applicant_phone, $content);
            $Status = json_decode($sms);

            if ($Status->message == 200 && $Status->status == "success") {
                if ($applicationForm) {
                    $app_sms_communication = new ApplicationCommunication;
                    $app_sms_communication->content = $content ?? "";
                    $app_sms_communication->communication_type = 1;
                    $app_sms_communication->application_id = $applicationForm->id;
                    $app_sms_communication->save();
                }
            }
        }
        //Send Email
        $emailTemplate = CompanyEmailTemplates::where(['tenant_id' => $tenantId, 'type' => 1])->first();
        if ($emailTemplate && $emailTemplate->automatically_send) {
            $content = $emailTemplate->subject;
            $appEmailCommunication = new ApplicationCommunication();
            $appEmailCommunication->content = $content ?? "";
            $appEmailCommunication->communication_type = 5;
            $appEmailCommunication->application_id = $applicationForm->id;
            $appEmailCommunication->save();
            $this->editApplicationFormController->sendStoreEmail($applicationForm->id, $emailTemplate);
        }

        // Log the information
        // Set the API endpoint URL
        $url = rtrim(env("APP_URL"), '/') . '/webhook/translations/' . ltrim($applicationForm->message, '/');
        // Schedule an asynchronous task to make the HTTP request
        // Dispatch a job to make the HTTP request asynchronously
        AsyncHttpRequestJob::dispatch(parse_url($url))->onQueue('async-http-requests');
        Log::info("PiggyFile");


        return back()->with('success_message', __('Application Saved Successfully'));
    }

    /**
     * This function will be called while displaying the new store application form
     *
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Foundation\Application
     */
    public function index(Request $request, $slug, $pageId, $tenant_id)
    {
        $tenantData = Tenant::where('domain_name', $slug)->first();
        $tenantId = '';
        if (isset($tenantData->id) && $tenantData->is_enabled == 1) {
            $tenantId = $tenantData->id;
        }
        $affiliate = Affiliate::get();
        $affiliate = $affiliate->where('tenant_id', $tenantId);
        // $slug = 1;
        $page_id = 1;
        $tenant_id = $tenant_id;
        return view('application-form', compact('slug', 'page_id', 'tenant_id', 'affiliate', 'tenantId'));
    }

    /**
     * This function will be called when users want to see all submited form requests.
     *
     * @return View
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function showApplicationsList(Request $request): View
    {
        $tenant = $request->session()->get('sessCompanyContext') ?? null;
        if ($this->redirectUser($request)) {
            return view("biolink.my_info.my_info", compact('tenant'));
        }
        $this->authorize('browse new_store_applications');
        /*$user = Auth::user();
        $tenantId = $user->tenant_id;*/

        $tenantId = '';
        if (isset($tenant->id) && $tenant->is_enabled == 1) {
            $tenantId = $tenant->id;
        }
        $applications = NewStoreApplication::query();
        if ($tenantId) {
            $applications = $applications->where('tenant_id', $tenantId);
        }
        $applications = $applications->orderBy('id', 'desc')->paginate(10);
        return view("biolink.applications.list", compact('applications'));
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function redirectUser($request)
    {
        $data = false;
        $user = auth()->user();
        $tenant = $request->session()->get('sessCompanyContext') ?? null;
        $userRoles = $user->roles;
        $userPlan = $tenant->plan;
        if ($userRoles[0]->name != 'superadmin' && $userPlan->is_free == 1) {
            $data = true;
        }
        return $data;
    }
}
