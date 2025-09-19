<?php
namespace Jubaer\Zoom;

use GuzzleHttp\Client;

/**
 * Summary of Zoom
 * https://developers.zoom.us/docs/api
 */
class Zoom {

    protected string $accessToken;
    protected Client $client;

    public function __construct( protected $account_id = null, protected $client_id = null, protected $client_secret = null ) {

        if ( auth()->check() ) {
            $user                = auth()->user();
            $this->client_id ??= method_exists( $user, 'zoomClientID' ) ? $user->clientID() : config( 'zoom.client_id' );
            $this->client_secret ??= method_exists( $user, 'zoomClientSecret' ) ? $user->clientSecret() : config( 'zoom.client_secret' );
            $this->account_id ??= method_exists( $user, 'zoomAccountID' ) ? $user->accountID() : config( 'zoom.account_id' );
        }
        else {
            $this->client_id ??= config( 'zoom.client_id' );
            $this->client_secret ??= config( 'zoom.client_secret' );
            $this->account_id ??= config( 'zoom.account_id' );
        }

        $this->accessToken = $this->getAccessToken();

        $this->client = new Client( [
            'base_uri' => 'https://api.zoom.us/v2/',
            'headers'  => [
                'Authorization' => "Bearer {$this->accessToken}",
                'Content-Type'  => 'application/json',
            ],
        ] );
    }

    protected function getAccessToken() {

        $client = new Client( [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( "{$this->client_id}:{$this->client_secret}" ),
                'Host'          => 'zoom.us',
            ],
        ] );

        $response = $client->request( 'POST', "https://zoom.us/oauth/token", [
            'form_params' => [
                'grant_type' => 'account_credentials',
                'account_id' => $this->account_id,
            ],
        ] );

        $responseBody = json_decode( $response->getBody(), true );
        return $responseBody['access_token'];
    }

    /**
     * Return a list of engagements.
     * https://developers.zoom.us/docs/api/contact-center/#tag/engagements/get/contact_center/engagements
     *
     * @param array $options Optional parameters:
     *   - next_page_token (string): Token for pagination
     *   - page_size (int): Number of items per page (default: 10, max: 100)
     *   - timezone (string): The call's timezone (default: UTC)
     *   - from (string): Start date (yyyy-mm-dd or yyyy-MM-dd'T'HH:mm:ss'Z')
     *   - to (string): End date (yyyy-mm-dd or yyyy-MM-dd'T'HH:mm:ss'Z')
     *   - queue_id (string): The queue's ID
     *   - user_id (string): The agent's ID
     *   - consumer_number (string): The customer's phone number
     *   - channel_sources (array): Channel sources
     *   - direction (string): Engagement direction (inbound|outbound)
     */
    public function getEngagements( array $options = [] ) {

        $error_count       = 0;
        $getEngagementsAPI = function (string $next_page_token = null) use (&$error_count, $options) {
            try {
                // Build query parameters
                $queryParams = [];

                // Set default page_size if not provided
                $queryParams['page_size'] = $options['page_size'] ?? 100;

                // Add pagination token
                if ( $next_page_token !== null ) {
                    $queryParams['next_page_token'] = $next_page_token;
                }

                // Add optional parameters if provided
                $allowedParams = [
                    'timezone', 'from', 'to', 'queue_id', 'user_id',
                    'consumer_number', 'direction'
                ];

                foreach ( $allowedParams as $param ) {
                    if ( isset( $options[$param] ) && $options[$param] !== '' ) {
                        $queryParams[$param] = $options[$param];
                    }
                }

                // Handle channel_sources array parameter
                if ( isset( $options['channel_sources'] ) && is_array( $options['channel_sources'] ) ) {
                    foreach ( $options['channel_sources'] as $channelSource ) {
                        $queryParams['channel_sources'][] = $channelSource;
                    }
                }

                $response = $this->client->request( 'GET', 'contact_center/engagements', [
                    'query' => $queryParams
                ] );
                $data = json_decode( $response->getBody(), true );
                return [
                    'status' => true,
                    'data'   => $data,
                ];
            } catch ( \Throwable $th ) {
                $error_count++;
                return [
                    'status'  => false,
                    'message' => $th->getMessage(),
                ];
            }
        };
        $next_page_token   = $options['next_page_token'] ?? null;
        while ( true ) {
            if ( $error_count > 5 ) {
                break;
            }
            $api_response = $getEngagementsAPI( $next_page_token );
            if ( $api_response === false ) {
                $error_count++;
                continue;
            }
            yield $api_response;
            if ( $api_response['status'] === true ) {
                $api_response_data = $api_response['data'];
                $next_page_token   = $api_response_data['next_page_token'];
                if ( ( $next_page_token ?? '' ) === '' ) {
                    break;
                }
            }
        }
    }

    /**
     * retrieve engagement
     * @param string $engagementId
     * @return array
     */
    public function getEngagement( string $engagementId ) {

        try {
            $response = $this->client->request( 'GET', "contact_center/engagements/{$engagementId}" );
            $data     = json_decode( $response->getBody(), true );
            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * Get an engagement's survey
     * https://developers.zoom.us/docs/api/rest/reference/contact-center/methods/#operation/getEngagementSurvey
     * @param string $engagementId The engagement's ID
     * @return array
     */
    public function getEngagementSurvey( string $engagementId ) {

        try {
            $response = $this->client->request( 'GET', "contact_center/engagements/{$engagementId}/survey" );
            $data     = json_decode( $response->getBody(), true );
            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // create meeting
    public function createMeeting( array $data ) {

        try {
            $response = $this->client->request( 'POST', 'users/me/meetings', [
                'json' => $data,
            ] );
            $res      = json_decode( $response->getBody(), true );
            return [
                'status' => true,
                'data'   => $res,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // update meeting
    public function updateMeeting( string $meetingId, array $data ) {

        try {
            $response = $this->client->request( 'PATCH', 'meetings/' . $meetingId, [
                'json' => $data,
            ] );
            $res      = json_decode( $response->getBody(), true );
            return [
                'status' => true,
                'data'   => $res,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get meeting
    public function getMeeting( string $meetingId ) {

        try {
            $response = $this->client->request( 'GET', 'meetings/' . $meetingId );
            $data     = json_decode( $response->getBody(), true );
            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get all meetings
    public function getAllMeeting() {

        try {
            $response = $this->client->request( 'GET', 'users/me/meetings' );
            $data     = json_decode( $response->getBody(), true );
            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get upcoming meetings
    public function getUpcomingMeeting() {

        try {
            $response = $this->client->request( 'GET', 'users/me/meetings?type=upcoming' );

            $data = json_decode( $response->getBody(), true );
            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get previous meetings
    public function getPreviousMeetings() {

        try {
            $meetings = $this->getAllMeeting();

            $previousMeetings = [];

            foreach ($meetings['meetings'] as $meeting) {
                $start_time = strtotime( $meeting['start_time'] );

                if ( $start_time < time() ) {
                    $previousMeetings[] = $meeting;
                }
            }

            return [
                'status' => true,
                'data'   => $previousMeetings,
            ]
            ;

        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get rescheduling meeting
    public function rescheduleMeeting( string $meetingId, array $data ) {

        try {
            $response = $this->client->request( 'PATCH', 'meetings/' . $meetingId, [
                'json' => $data,
            ] );
            if ( $response->getStatusCode() === 204 ) {
                return [
                    'status'  => true,
                    'message' => 'Meeting Rescheduled Successfully',
                ];
            }
            else {
                return [
                    'status'  => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // end meeting
    public function endMeeting( $meetingId ) {

        try {
            $response = $this->client->request( 'PUT', 'meetings/' . $meetingId . '/status', [
                'json' => [
                    'action' => 'end',
                ],
            ] );
            if ( $response->getStatusCode() === 204 ) {
                return [
                    'status'  => true,
                    'message' => 'Meeting Ended Successfully',
                ];
            }
            else {
                return [
                    'status'  => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // delete meeting
    public function deleteMeeting( string $meetingId ) {

        try {
            $response = $this->client->request( 'DELETE', 'meetings/' . $meetingId );
            if ( $response->getStatusCode() === 204 ) {
                return [
                    'status'  => true,
                    'message' => 'Meeting Deleted Successfully',
                ];
            }
            else {
                return [
                    'status'  => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }

    }

    // recover meeting
    public function recoverMeeting( $meetingId ) {

        try {
            $response = $this->client->request( 'PUT', 'meetings/' . $meetingId . '/status', [
                'json' => [
                    'action' => 'recover',
                ],
            ] );

            if ( $response->getStatusCode() === 204 ) {
                return [
                    'status'  => true,
                    'message' => 'Meeting Recovered Successfully',
                ];
            }
            else {
                return [
                    'status'  => false,
                    'message' => 'Something went wrong',
                ];
            }
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    // get users list
    public function getUsers( $data ) {

        try {
            $response             = $this->client->request( 'GET', 'users', [
                'query' => [
                    'page_size'   => @$data['page_size'] ?? 300,
                    'status'      => @$data['status'] ?? 'active',
                    'page_number' => @$data['page_number'] ?? 1,
                ],
            ] );
            $responseData         = json_decode( $response->getBody(), true );
            $data                 = [];
            $data['current_page'] = $responseData['page_number'];
            $data['profile']      = $responseData['users'][0];
            $data['last_page']    = $responseData['page_count'];
            $data['per_page']     = $responseData['page_size'];
            $data['total']        = $responseData['total_records'];
            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    public function sendSMSMessage( string $senderUserId, string $senderPhoneNumber, string $recipientPhoneNumber, string $message, array $attachments = [] ) {

        try {
            $response = $this->client->request( 'POST', 'phone/sms/messages', [
                'json' => [
                    'sender'      => [
                        'user_id'      => $senderUserId,
                        'phone_number' => $senderPhoneNumber,
                    ],
                    'to_members'  => [
                        [
                            'phone_number' => $recipientPhoneNumber,
                        ],
                    ],
                    'message'     => $message,
                    'attachments' => $attachments,
                ],
            ] );
            return [
                'status' => true,
                'data'   => json_decode( $response->getBody(), true ),
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }

    }

    /**
     * List hsitorical engagement dataset data
     * https://developers.zoom.us/docs/api/contact-center/#tag/reports-v2cx-analytics/get/contact_center/analytics/dataset/historical/engagement
     * @param string $from GMT yyyy-MM-dd'T'HH:mm:ss'Z' (earliest data avilable is from 2 years ago)
     * @param string $to GMT yyyy-MM-dd'T'HH:mm:ss'Z' (earliest data avilable is from 2 years ago)
     */
    public function getContactCenterAnalyticsDatasetHistoricalEngagement() {

        $url = 'contact_center/analytics/dataset/historical/engagement';
        try {
            $first           = true;
            $next_page_token = '';
            while ( $first || ( ( $next_page_token ?? '' ) !== '' ) ) {
                $query = [
                    'page_size' => 300,
                ];
                if ( ( $next_page_token ?? '' ) !== '' ) {
                    $query['next_page_token'] = $next_page_token;
                }
                $response     = $this->client->request( 'GET', $url, [ 'query' => $query ] );
                $responseData = json_decode( $response->getBody(), true );

                $next_page_token = $responseData['next_page_token'] ?? null;

                $data  = $responseData['engagements'];
                $first = false;
            }

            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * List call queues
     * https://developers.zoom.us/docs/api/phone/#tag/call-queues/get/phone/call_queues
     */
    public function getPhoneCallQueues() {

        $url = 'phone/call_queues';
        try {
            $first           = true;
            $next_page_token = '';
            while ( $first || ( ( $next_page_token ?? '' ) !== '' ) ) {
                $query = [
                    'page_size' => 100,
                ];
                if ( ( $next_page_token ?? '' ) !== '' ) {
                    $query['next_page_token'] = $next_page_token;
                }
                $response     = $this->client->request( 'GET', $url, [ 'query' => $query ] );
                $responseData = json_decode( $response->getBody(), true );

                $next_page_token = $responseData['next_page_token'] ?? null;

                $data  = $responseData['call_queues'];
                $first = false;
            }

            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * List call queue analytics
     * https://developers.zoom.us/docs/api/phone/#tag/call-queues/get/phone/call_queue_analytics
     * @param string $from GMT yyyy-MM-dd'T'HH:mm:ss'Z' (earliest data avilable is from 2 years ago)
     * @param string $to GMT yyyy-MM-dd'T'HH:mm:ss'Z' (earliest data avilable is from 2 years ago)
     */
    public function getPhoneCallQueueAnalytics( string $from, string $to ) {

        $url = 'phone/call_queue_analytics';
        try {
            $first           = true;
            $next_page_token = '';
            while ( $first || ( ( $next_page_token ?? '' ) !== '' ) ) {
                $query = [
                    'page_size' => 300,
                    'from'      => $from,
                    'to'        => $to,
                ];
                if ( ( $next_page_token ?? '' ) !== '' ) {
                    $query['next_page_token'] = $next_page_token;
                }
                $response     = $this->client->request( 'GET', $url, [ 'query' => $query ] );
                $responseData = json_decode( $response->getBody(), true );

                $next_page_token = $responseData['next_page_token'] ?? null;

                $data  = $responseData; //['call_queues']
                $first = false;
            }

            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * List queues​
     * https://developers.zoom.us/docs/api/contact-center/#tag/queues/get/contact_center/queues
     */
    public function getContactCenterQueues() {

        $url = 'contact_center/queues';
        // $parameters = [];
        // if ( $channel !== null ) {
        //     $parameters[] = "channel={$channel}";
        // }
        // if ( count( $parameters ) > 0 ) {
        //     $url = $url . '?' . implode( '&', $parameters );
        // }
        try {
            $first           = true;
            $next_page_token = '';
            while ( $first || ( ( $next_page_token ?? '' ) !== '' ) ) {
                $query = [
                    'page_size' => 300,
                ];
                if ( ( $next_page_token ?? '' ) !== '' ) {
                    $query['next_page_token'] = $next_page_token;
                }
                $response     = $this->client->request( 'GET', $url, [ 'query' => $query ] );
                $responseData = json_decode( $response->getBody(), true );

                $next_page_token = $responseData['next_page_token'] ?? null;

                $data  = $responseData['queues'];
                $first = false;
            }

            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * List queue agents
     * https://developers.zoom.us/docs/api/contact-center/#tag/queues/get/contact_center/queues/%7BqueueId%7D/agents
     */
    public function getContactCenterQueueAgents( string $queueId ) {

        $url = "contact_center/queues/{$queueId}/agents";
        try {
            $first           = true;
            $next_page_token = '';
            while ( $first || ( ( $next_page_token ?? '' ) !== '' ) ) {
                $query = [
                    'page_size' => 300,
                ];
                if ( ( $next_page_token ?? '' ) !== '' ) {
                    $query['next_page_token'] = $next_page_token;
                }
                $response     = $this->client->request( 'GET', $url, [ 'query' => $query ] );
                $responseData = json_decode( $response->getBody(), true );

                $next_page_token = $responseData['next_page_token'] ?? null;

                $data  = $responseData['agents'];
                $first = false;
            }

            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * Get a queue's operating hours
     * https://developers.zoom.us/docs/api/contact-center/#tag/queues/get/contact_center/queues/{queueId}/operating_hours
     */
    public function getContactCenterQueueOperatingHours( string $queueId ) {

        $url = "contact_center/queues/{$queueId}/operating_hours";
        try {
            $first           = true;
            $next_page_token = '';
            while ( $first || ( ( $next_page_token ?? '' ) !== '' ) ) {
                $query = [
                    'page_size' => 300,
                ];
                if ( ( $next_page_token ?? '' ) !== '' ) {
                    $query['next_page_token'] = $next_page_token;
                }
                $response     = $this->client->request( 'GET', $url, [ 'query' => $query ] );
                $responseData = json_decode( $response->getBody(), true );

                $next_page_token = $responseData['next_page_token'] ?? null;
                unset( $responseData['next_page_token'] );

                $data  = $responseData;
                $first = false;
                break;
            }

            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * List business hours
     * https://developers.zoom.us/docs/api/contact-center/#tag/operating-hours/get/contact_center/business_hours
     */
    public function getContactCenterBusinessHoursList() {

        $url = "contact_center/business_hours";
        try {
            $first           = true;
            $next_page_token = '';
            while ( $first || ( ( $next_page_token ?? '' ) !== '' ) ) {
                $query = [
                    'page_size' => 300,
                ];
                if ( ( $next_page_token ?? '' ) !== '' ) {
                    $query['next_page_token'] = $next_page_token;
                }
                $response     = $this->client->request( 'GET', $url, [ 'query' => $query ] );
                $responseData = json_decode( $response->getBody(), true );

                $next_page_token = $responseData['next_page_token'] ?? null;

                $data  = $responseData['business_hours'];
                $first = false;
            }

            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * List closures
     * https://developers.zoom.us/docs/api/contact-center/#tag/operating-hours/get/contact_center/closures
     */
    public function getContactCenterClosuresList() {

        $url = "contact_center/closures";
        try {
            $first           = true;
            $next_page_token = '';
            while ( $first || ( ( $next_page_token ?? '' ) !== '' ) ) {
                $query = [
                    'page_size' => 300,
                ];
                if ( ( $next_page_token ?? '' ) !== '' ) {
                    $query['next_page_token'] = $next_page_token;
                }
                $response     = $this->client->request( 'GET', $url, [ 'query' => $query ] );
                $responseData = json_decode( $response->getBody(), true );

                $next_page_token = $responseData['next_page_token'] ?? null;

                $data  = $responseData['closure_sets'];
                $first = false;
            }

            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'message' => $th->getMessage(),
            ];
        }
    }

}
