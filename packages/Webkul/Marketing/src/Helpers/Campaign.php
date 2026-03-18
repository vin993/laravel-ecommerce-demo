<?php

namespace Webkul\Marketing\Helpers;

use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Webkul\Core\Models\SubscribersList;
use Webkul\Marketing\Mail\NewsletterMail;
use Webkul\Marketing\Repositories\CampaignRepository;
use Webkul\Marketing\Repositories\EventRepository;

class Campaign
{
    /**
     * Create a new helper instance.
     *
     *
     * @return void
     */
    public function __construct(
        protected EventRepository $eventRepository,
        protected CampaignRepository $campaignRepository
    ) {}

    /**
     * Process the email.
     */
    public function process(): void
    {
        $campaigns = $this->campaignRepository->getModel()
            ->leftJoin('marketing_events', 'marketing_campaigns.marketing_event_id', 'marketing_events.id')
            ->leftJoin('marketing_templates', 'marketing_campaigns.marketing_template_id', 'marketing_templates.id')
            ->select('marketing_campaigns.*')
            ->where('marketing_campaigns.status', 1)
            ->where('marketing_templates.status', 'active')
            ->where(function ($query) {
                $query->where('marketing_events.date', Carbon::now()->format('Y-m-d'))
                    ->orWhereNull('marketing_events.date');
            })
            ->get();

        foreach ($campaigns as $campaign) {
            if ($campaign->event->name == 'Birthday') {
                $emails = $this->getBirthdayEmails($campaign);
            } else {
                $emails = $this->getEmailAddresses($campaign);
            }

            foreach ($emails as $email) {
                Mail::send(new NewsletterMail($email, $campaign));
            }
        }
    }

    /**
     * Get the email address.
     *
     * @param  \Webkul\Marketing\Contracts\Campaign  $campaign
     * @return array
     */
    public function getEmailAddresses($campaign)
    {
        \Log::info('Getting email addresses for campaign', [
            'campaign_id' => $campaign->id,
            'customer_group_id' => $campaign->customer_group_id,
            'customer_group_code' => $campaign->customer_group->code
        ]);

        if ($campaign->customer_group->code === 'guest') {
            \Log::info('Customer group is guest, checking subscribers_list table');
            $customerGroupEmails = SubscribersList::whereNull('customer_id');

            $count = $customerGroupEmails->count();
            \Log::info('Guest subscribers found', ['count' => $count]);
        } else {
            \Log::info('Customer group is NOT guest, checking registered customers with subscription');
            $customerGroupEmails = $campaign->customer_group->customers()->where('subscribed_to_news_letter', 1);

            $count = $customerGroupEmails->count();
            \Log::info('Subscribed customers in group found', ['count' => $count]);

            // If no subscribed customers, try to get all newsletter subscribers from subscribers_list
            if ($count === 0) {
                \Log::info('No subscribed customers found, checking subscribers_list table for all subscribers');
                $allSubscribers = SubscribersList::all();
                \Log::info('All subscribers in subscribers_list', [
                    'count' => $allSubscribers->count(),
                    'emails' => $allSubscribers->pluck('email')->toArray()
                ]);

                // Use all newsletter subscribers regardless of customer group
                $customerGroupEmails = SubscribersList::query();
            }
        }

        $emails = array_unique($customerGroupEmails->pluck('email')->toArray());
        \Log::info('Final email list', ['emails' => $emails]);

        return $emails;
    }

    /**
     * Return customer's emails who has a birthday today.
     *
     * @param  \Webkul\Marketing\Contracts\Campaign  $campaign
     * @return array
     */
    public function getBirthdayEmails($campaign)
    {
        return $campaign->customer_group
            ->customers()
            ->whereRaw('DATE_FORMAT(date_of_birth, "%m-%d") = ?', [Carbon::now()->format('m-d')])
            ->where('subscribed_to_news_letter', 1)
            ->pluck('email')
            ->toArray();
    }
}
