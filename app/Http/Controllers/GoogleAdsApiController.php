<?php

/**
 * Copyright 2020 Google LLC
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     https://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace App\Http\Controllers;

use Google\Ads\GoogleAds\Lib\V14\GoogleAdsClient;
use Google\Ads\GoogleAds\Util\FieldMasks;
use Google\Ads\GoogleAds\Util\V14\ResourceNames;
use Google\Ads\GoogleAds\V14\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V14\Resources\Campaign;
use Google\Ads\GoogleAds\V14\Services\CampaignOperation;
use Google\Ads\GoogleAds\V14\Services\GoogleAdsRow;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class GoogleAdsApiController extends Controller
{
    private const CUSTOMER_ID = 'INSERT_CUSTOMER_ID_HERE';

    private static $REPORT_TYPE_TO_DEFAULT_SELECTED_FIELDS = [
        'campaign' => ['campaign.id', 'campaign.name', 'campaign.status'],
        'customer' => ['customer.id']
    ];

    private const RESULTS_LIMIT = 100;

    /**
     * Controls a POST redirect uri from Google OAuth2.
     * Print the text code to create the credentials.
     * @return
     */
    public function getCodeAction(
        Request $request
    ) {
        $str = 'Copy the code text to enter your OAuth2 client code to the Terminal: ';
        dd($str . $request->input('code'));

        return;
    }

    /**
     * Controls a POST or GET request submitted in the context of the "Show Report" form.
     *
     * @param Request $request the HTTP request
     * @param GoogleAdsClient $googleAdsClient the Google Ads API client
     * @return View the view to redirect to after processing
     */
    public function showReportAction(
        Request $request,
        GoogleAdsClient $googleAdsClient
    ): View {
        if ($request->method() === 'POST') {
            // Retrieves the form inputs.
            $customerId = $request->input('customerId');
            $reportType = $request->input('reportType');
            $reportRange = $request->input('reportRange');
            $entriesPerPage = $request->input('entriesPerPage');

            // Retrieves the list of metric fields to select filtering out the static ones.
            $selectedFields = array_values(
                $request->except(
                    [
                        '_token',
                        'customerId',
                        'reportType',
                        'reportRange',
                        'entriesPerPage'
                    ]
                )
            );

            // Merges the list of metric fields to the resource ones that are selected by default.
            $selectedFields = array_merge(
                self::$REPORT_TYPE_TO_DEFAULT_SELECTED_FIELDS[$reportType],
                $selectedFields
            );

            // Builds the GAQL query.
            $query = sprintf(
                "SELECT %s FROM %s WHERE metrics.impressions > 0 AND segments.date " .
                "DURING %s LIMIT %d",
                join(", ", $selectedFields),
                $reportType,
                $reportRange,
                self::RESULTS_LIMIT
            );

            // Initializes the list of page tokens. Page tokens are used to request specific pages
            // of results from the API. They are especially useful to optimize navigation between
            // pages as there is no need to cache all the results before displaying.
            // More details can be found here:
            // https://developers.google.com/google-ads/api/docs/reporting/paging.
            //
            // The first page's token is always an empty string.
            $pageTokens = [''];

            // Updates the session with all the information that is necessary to process any
            // future requests (report result pages).
            $request->session()->put('customerId', $customerId);
            $request->session()->put('selectedFields', $selectedFields);
            $request->session()->put('entriesPerPage', $entriesPerPage);
            $request->session()->put('query', $query);
            $request->session()->put('pageTokens', $pageTokens);
        } else {
            // Loads from the session all the information that is necessary to process any
            // requests (report result page).
            $customerId = $request->session()->get('customerId');
            $selectedFields = $request->session()->get('selectedFields');
            $entriesPerPage = $request->session()->get('entriesPerPage');
            $query = $request->session()->get('query');
            $pageTokens = $request->session()->get('pageTokens');
        }

        // Determines the number of the page to load (the first one by default).
        $pageNo = $request->input('page') ?: 1;

        // Fetches next pages in sequence and stores their page tokens until the page token of the
        // requested page is retrieved.
        while (count($pageTokens) < $pageNo) {
            // Fetches the next unknown page.
            $response = $googleAdsClient->getGoogleAdsServiceClient()->search(
                $customerId,
                $query,
                [
                    'pageSize' => $entriesPerPage,
                    // Requests to return the total results count. This is necessary to
                    // determine how many pages of results exist.
                    'returnTotalResultsCount' => true,
                    // There is no need to go over the pages we already know the page tokens for.
                    // Fetches the last page we know the page token for so that we can retrieve the
                    // token of the page that comes after it.
                    'pageToken' => end($pageTokens)
                ]
            );
            if ($response->getPage()->getNextPageToken()) {
                // Stores the page token of the page that comes after the one we just fetched if
                // any so that it can be reused later if necessary.
                $pageTokens[] = $response->getPage()->getNextPageToken();
            } else {
                // Otherwise changes the requested page number for the latest page that we have
                // fetched until now, the requested page number was invalid.
                $pageNo = count($pageTokens);
            }
        }

        // Fetches the actual page that we want to display the results of.
        $response = $googleAdsClient->getGoogleAdsServiceClient()->search(
            $customerId,
            $query,
            [
                'pageSize' => $entriesPerPage,
                // Requests to return the total results count. This is necessary to
                // determine how many pages of results exist.
                'returnTotalResultsCount' => true,
                // The page token of the requested page is in the page token list because of the
                // processing done in the previous loop.
                'pageToken' => $pageTokens[$pageNo - 1]
            ]
        );
        if ($response->getPage()->getNextPageToken()) {
            // Stores the page token of the page that comes after the one we just fetched if any so
            // that it can be reused later if necessary.
            $pageTokens[] = $response->getPage()->getNextPageToken();
        }

        // Determines the total number of results to display.
        // The total results count does not take into consideration the LIMIT clause of the query
        // so we need to find the minimal value between the limit and the total results count.
        $totalNumberOfResults = min(
            self::RESULTS_LIMIT,
            $response->getPage()->getResponseObject()->getTotalResultsCount()
        );

        // Extracts the results for the requested page.
        $results = [];
        foreach ($response->getPage()->getIterator() as $googleAdsRow) {
            /** @var GoogleAdsRow $googleAdsRow */
            // Converts each result as a Plain Old PHP Object (POPO) using JSON.
            $results[] = json_decode($googleAdsRow->serializeToJsonString(), true);
        }

        // Creates a length aware paginator to supply a given page of results for the view.
        $paginatedResults = new LengthAwarePaginator(
            $results,
            $totalNumberOfResults,
            $entriesPerPage,
            $pageNo,
            ['path' => url('show-report')]
        );

        // Updates the session with the known page tokens to avoid unnecessary requests during
        // future page navigation.
        $request->session()->put('pageTokens', $pageTokens);

        // Redirects to the view that displays fields of paginated report results.
        return view(
            'report-result',
            compact('paginatedResults', 'selectedFields')
        );
    }

    /**
     * Controls a POST request submitted in the context of the "Pause Campaign" form.
     *
     * @param Request $request the HTTP request
     * @param GoogleAdsClient $googleAdsClient the Google Ads API client
     * @return View the view to redirect to after processing
     */
    public function pauseCampaignAction(
        Request $request,
        GoogleAdsClient $googleAdsClient
    ): View {
        // Retrieves the form inputs.
        $customerId = $request->input('customerId');
        $campaignId = $request->input('campaignId');

        // Deducts the campaign resource name from the given IDs.
        $campaignResourceName = ResourceNames::forCampaign($customerId, $campaignId);

        // Creates a campaign object and sets its status to PAUSED.
        $campaign = new Campaign();
        $campaign->setResourceName($campaignResourceName);
        $campaign->setStatus(CampaignStatus::PAUSED);

        // Constructs an operation that will pause the campaign with the specified resource
        // name, using the FieldMasks utility to derive the update mask. This mask tells the
        // Google Ads API which attributes of the campaign need to change.
        $campaignOperation = new CampaignOperation();
        $campaignOperation->setUpdate($campaign);
        $campaignOperation->setUpdateMask(FieldMasks::allSetFieldsOf($campaign));

        // Issues a mutate request to pause the campaign.
        $googleAdsClient->getCampaignServiceClient()->mutateCampaigns(
            $customerId,
            [$campaignOperation]
        );

        // Builds the GAQL query to retrieve more information about the now paused campaign.
        $query = sprintf(
            "SELECT campaign.id, campaign.name, campaign.status FROM campaign " .
            "WHERE campaign.resource_name = '%s' LIMIT 1",
            $campaignResourceName
        );

        // Searches the result.
        $response = $googleAdsClient->getGoogleAdsServiceClient()->search(
            $customerId,
            $query
        );

        // Fetches and converts the result as a POPO using JSON.
        $campaign = json_decode(
            $response->iterateAllElements()->current()->getCampaign()->serializeToJsonString(),
            true
        );

        return view(
            'pause-result',
            compact('customerId', 'campaign')
        );
    }

    public static function getCampaignAction(Request $request, GoogleAdsClient $googleAdsClient)
    {
        // Either pass the required parameters for this example on the command line, or insert them
        // into the constants above.
        $options = (new ArgumentParser())->parseCommandArguments([
            ArgumentNames::CUSTOMER_ID => GetOpt::REQUIRED_ARGUMENT
        ]);

        // Generate a refreshable OAuth2 credential for authentication.
        $oAuth2Credential = (new OAuth2TokenBuilder())->fromFile()->build();
        
        // Construct a Google Ads client configured from a properties file and the
        // OAuth2 credentials above.
        $googleAdsClient = (new GoogleAdsClientBuilder())
            ->fromFile()
            ->withOAuth2Credential($oAuth2Credential)
            ->build();

        try {
            self::runExample(
                $googleAdsClient,
                $options[ArgumentNames::CUSTOMER_ID] ?: self::CUSTOMER_ID
            );
        } catch (GoogleAdsException $googleAdsException) {
            printf(
                "Request with ID '%s' has failed.%sGoogle Ads failure details:%s",
                $googleAdsException->getRequestId(),
                PHP_EOL,
                PHP_EOL
            );
            foreach ($googleAdsException->getGoogleAdsFailure()->getErrors() as $error) {
                /** @var GoogleAdsError $error */
                printf(
                    "\t%s: %s%s",
                    $error->getErrorCode()->getErrorCode(),
                    $error->getMessage(),
                    PHP_EOL
                );
            }
            exit(1);
        } catch (ApiException $apiException) {
            printf(
                "ApiException was thrown with message '%s'.%s",
                $apiException->getMessage(),
                PHP_EOL
            );
            exit(1);
        }
    }

    /**
     * Runs the example.
     *
     * @param GoogleAdsClient $googleAdsClient the Google Ads API client
     * @param int $customerId the customer ID
     */
    public static function runExample(GoogleAdsClient $googleAdsClient, int $customerId)
    {
        $googleAdsServiceClient = $googleAdsClient->getGoogleAdsServiceClient();
        // Creates a query that retrieves all campaigns.
        $query = 'SELECT campaign.id, campaign.name FROM campaign ORDER BY campaign.id';
        // Issues a search stream request.
        /** @var GoogleAdsServerStreamDecorator $stream */
        $stream =
            $googleAdsServiceClient->searchStream($customerId, $query);

        // Iterates over all rows in all messages and prints the requested field values for
        // the campaign in each row.
        foreach ($stream->iterateAllElements() as $googleAdsRow) {
            /** @var GoogleAdsRow $googleAdsRow */
            printf(
                "Campaign with ID %d and name '%s' was found.%s",
                $googleAdsRow->getCampaign()->getId(),
                $googleAdsRow->getCampaign()->getName(),
                PHP_EOL
            );
        }
    }

}
