<?php

namespace Webkul\Admin\Http\Controllers\Marketing\Communications;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Webkul\Admin\DataGrids\Marketing\Communications\CampaignDataGrid;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Marketing\Helpers\Campaign as CampaignHelper;
use Webkul\Marketing\Mail\NewsletterMail;
use Webkul\Marketing\Repositories\CampaignRepository;
use Webkul\Marketing\Repositories\TemplateRepository;

class CampaignController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected CampaignRepository $campaignRepository,
        protected TemplateRepository $templateRepository,
        protected CampaignHelper $campaignHelper,
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(CampaignDataGrid::class)->process();
        }

        return view('admin::marketing.communications.campaigns.index');
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        $templates = $this->templateRepository->findByField('status', 'active');

        return view('admin::marketing.communications.campaigns.create', compact('templates'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function store()
    {
        \Log::info('=== CAMPAIGN STORE METHOD CALLED ===');
        \Log::info('Request data: ', request()->all());

        $validatedData = $this->validate(request(), [
            'name'                  => 'required',
            'subject'               => 'required',
            'marketing_template_id' => 'required',
            'marketing_event_id'    => 'required',
            'channel_id'            => 'required',
            'customer_group_id'     => 'required',
            'status'                => 'sometimes|required|in:0,1',
        ]);

        \Log::info('Validated data: ', $validatedData);

        Event::dispatch('marketing.campaigns.create.before');

        $campaign = $this->campaignRepository->create($validatedData);

        // Reload campaign with all relationships
        $campaign = $campaign->load(['email_template', 'event', 'customer_group']);

        \Log::info('Campaign created', [
            'id' => $campaign->id,
            'status' => $campaign->status,
            'status_type' => gettype($campaign->status),
            'template_loaded' => isset($campaign->email_template),
            'template_id' => $campaign->email_template->id ?? 'null',
            'template_content_length' => strlen($campaign->email_template->content ?? '')
        ]);

        Event::dispatch('marketing.campaigns.create.after', $campaign);

        // Send campaign emails immediately if status is active
        \Log::info('Checking campaign status', [
            'status' => $campaign->status,
            'comparison' => $campaign->status == 1 ? 'true' : 'false'
        ]);

        if ($campaign->status == 1) {
            \Log::info('Status is 1, calling sendCampaignEmails');
            $this->sendCampaignEmails($campaign);
        } else {
            \Log::info('Status is NOT 1, skipping email send', ['status' => $campaign->status]);
        }

        session()->flash('success', trans('admin::app.marketing.communications.campaigns.create-success'));

        return redirect()->route('admin.marketing.communications.campaigns.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @return \Illuminate\View\View
     */
    public function edit(int $id)
    {
        $campaign = $this->campaignRepository->findOrFail($id);

        $templates = $this->templateRepository->findByField('status', 'active');

        return view('admin::marketing.communications.campaigns.edit', compact('campaign', 'templates'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function update(int $id)
    {
        $validatedData = $this->validate(request(), [
            'name'                  => 'required',
            'subject'               => 'required',
            'marketing_template_id' => 'required',
            'marketing_event_id'    => 'required',
            'channel_id'            => 'required',
            'customer_group_id'     => 'required',
        ]);

        Event::dispatch('marketing.campaigns.update.before', $id);

        $campaign = $this->campaignRepository->update([
            ...$validatedData,
            'status' => request()->input('status') ? 1 : 0,
        ], $id);

        Event::dispatch('marketing.campaigns.update.after', $campaign);

        // Send campaign emails immediately if status is active
        if ($campaign->status == 1) {
            $this->sendCampaignEmails($campaign);
        }

        session()->flash('success', trans('admin::app.marketing.communications.campaigns.update-success'));

        return redirect()->route('admin.marketing.communications.campaigns.index');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            Event::dispatch('marketing.campaigns.delete.before', $id);

            $this->campaignRepository->delete($id);

            Event::dispatch('marketing.campaigns.delete.after', $id);

            return new JsonResponse([
                'message' => trans('admin::app.marketing.communications.campaigns.delete-success'),
            ]);
        } catch (\Exception $e) {
        }

        return new JsonResponse([
            'message' => trans('admin::app.marketing.communications.campaigns.delete-failed', [
                'name' => 'admin::app.marketing.communications.campaigns.email-campaign',
            ]),
        ], 500);
    }

    /**
     * Send campaign emails immediately
     *
     * @param  \Webkul\Marketing\Contracts\Campaign  $campaign
     * @return void
     */
    protected function sendCampaignEmails($campaign)
    {
        try {
            \Log::info('Campaign email sending started', [
                'campaign_id' => $campaign->id,
                'campaign_name' => $campaign->name,
                'status' => $campaign->status
            ]);

            // Check if campaign has valid template and event
            if (!$campaign->email_template) {
                \Log::warning('Campaign has no email template', ['campaign_id' => $campaign->id]);
                return;
            }

            if ($campaign->email_template->status != 'active') {
                \Log::warning('Campaign email template is not active', [
                    'campaign_id' => $campaign->id,
                    'template_status' => $campaign->email_template->status
                ]);
                return;
            }

            \Log::info('Campaign template validated', [
                'template_id' => $campaign->email_template->id,
                'template_name' => $campaign->email_template->name
            ]);

            // Get email addresses based on campaign type
            if ($campaign->event->name == 'Birthday') {
                $emails = $this->campaignHelper->getBirthdayEmails($campaign);
            } else {
                $emails = $this->campaignHelper->getEmailAddresses($campaign);
            }

            \Log::info('Email addresses retrieved', [
                'campaign_id' => $campaign->id,
                'email_count' => count($emails),
                'emails' => $emails
            ]);

            if (empty($emails)) {
                \Log::warning('No email addresses found for campaign', ['campaign_id' => $campaign->id]);
                return;
            }

            // Send emails immediately
            $sentCount = 0;
            foreach ($emails as $email) {
                Mail::send(new NewsletterMail($email, $campaign));
                $sentCount++;
                \Log::info('Email sent', ['to' => $email, 'campaign_id' => $campaign->id]);
            }

            \Log::info('Campaign emails sent successfully', [
                'campaign_id' => $campaign->id,
                'sent_count' => $sentCount
            ]);
        } catch (\Exception $e) {
            // Log error but don't break the campaign creation/update flow
            \Log::error('Campaign email sending failed', [
                'campaign_id' => $campaign->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
