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
     * Refresh the OAuth access token mid-session. Zoom tokens are ~1 hour TTL,
     * so a long-running bulk job (>1h) will start receiving 401s once the
     * initial token expires. requestWithBackoff() calls this on a 401 and
     * retries the failed request with the new token.
     *
     * Rebuilds the underlying Guzzle client with the new Authorization header
     * — the existing client has the token baked into its default headers.
     */
    protected function refreshAccessToken(): void {

        $this->accessToken = $this->getAccessToken();
        $this->client      = new Client( [
            'base_uri' => 'https://api.zoom.us/v2/',
            'headers'  => [
                'Authorization' => "Bearer {$this->accessToken}",
                'Content-Type'  => 'application/json',
            ],
        ] );
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
     * Issue a Guzzle request with retry-with-backoff for transient failures.
     *
     * Retries on:
     *   - HTTP 429 (rate limited) — honors Retry-After header when present
     *   - HTTP 5xx (server error) — exponential backoff
     *   - Connection-level exceptions (timeout, socket reset, etc.)
     *
     * Does NOT retry on 4xx other than 429 (those are caller errors). Caller
     * errors get rethrown so callers can distinguish them via the catch path.
     *
     * @param  string $method   HTTP method
     * @param  string $path     Path relative to the client's base_uri
     * @param  array  $options  Guzzle options (query, json, headers, etc.)
     * @param  int    $maxAttempts  Total attempts before giving up (default 4)
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \GuzzleHttp\Exception\GuzzleException When all retries exhausted
     */
    protected function requestWithBackoff( string $method, string $path, array $options = [], int $maxAttempts = 4 ) {

        $attempt        = 0;
        $tokenRefreshed = false;
        $lastError      = null;

        while ( $attempt < $maxAttempts ) {
            $attempt++;
            try {
                $response = $this->client->request( $method, $path, $options + [ 'http_errors' => true ] );
                return $response;
            } catch ( \GuzzleHttp\Exception\BadResponseException $e ) {
                $status = $e->getResponse() ? $e->getResponse()->getStatusCode() : 0;

                // 401 = expired access token. Zoom OAuth tokens are ~1 hour TTL;
                // long-running bulk jobs blow past that. Refresh once and retry
                // the same request. If the refresh itself fails or a second 401
                // comes back, fall through to the normal failure path.
                if ( $status === 401 && ! $tokenRefreshed ) {
                    try {
                        $this->refreshAccessToken();
                        $tokenRefreshed = true;
                        $attempt--; // don't count the token-refresh retry against attempt budget
                        continue;
                    } catch ( \Throwable $refreshError ) {
                        // Refresh failed — give up on this request, surface the original 401
                        throw $e;
                    }
                }

                $isRetryable = $status === 429 || ( $status >= 500 && $status <= 599 );

                if ( ! $isRetryable || $attempt >= $maxAttempts ) {
                    // Either a non-retryable 4xx or we've used all attempts.
                    throw $e;
                }

                // Honor Retry-After when 429; otherwise exponential backoff
                // starting at 1s and doubling each attempt (1, 2, 4, 8).
                $sleepMs = 0;
                if ( $status === 429 && $e->getResponse()->hasHeader( 'Retry-After' ) ) {
                    $retryAfter = $e->getResponse()->getHeader( 'Retry-After' )[0];
                    $sleepMs = is_numeric( $retryAfter )
                        ? ( (int) $retryAfter ) * 1000
                        : max( 1000, (int) ( ( strtotime( $retryAfter ) - time() ) * 1000 ) );
                }
                if ( $sleepMs <= 0 ) {
                    $sleepMs = ( 2 ** ( $attempt - 1 ) ) * 1000; // 1s, 2s, 4s, 8s
                }
                usleep( $sleepMs * 1000 );
                $lastError = $e;
            } catch ( \GuzzleHttp\Exception\ConnectException $e ) {
                // Connection-level (DNS, refused, timeout, SSL) — also retryable.
                if ( $attempt >= $maxAttempts ) {
                    throw $e;
                }
                usleep( ( 2 ** ( $attempt - 1 ) ) * 1_000_000 );
                $lastError = $e;
            }
        }

        // Should be unreachable — every code path either returns or throws.
        throw $lastError ?? new \RuntimeException( 'requestWithBackoff: exhausted retries' );
    }

    /**
     * Get QM interaction for an engagement.
     * https://developers.zoom.us/docs/api/quality-management/
     * Requires scopes: zoom_quality_management:read:interactions:admin
     *
     * Returns one of three shapes — callers MUST distinguish them, because
     * "no data" and "transient failure" need different downstream handling
     * (e.g., stamp synced_at on no-data; preserve for retry on transient):
     *   1. status=true  + data    → QM payload
     *   2. status=false + reason='no_data'  + message → Zoom returned 200 with empty interactions
     *   3. status=false + reason='api_error'+ message + http_status → request retries exhausted
     *
     * @param string $engagementId The engagement ID to look up QM data for
     * @return array
     */
    public function getQMInteraction( string $engagementId ) {

        try {
            $response = $this->requestWithBackoff( 'GET', 'qm/interactions', [
                'query' => [
                    'engagement_id' => $engagementId,
                    'page_size'     => 1,
                ],
            ] );
            $data = json_decode( $response->getBody(), true );

            $interactions = $data['interactions'] ?? [];
            if ( empty( $interactions ) ) {
                return [
                    'status'  => false,
                    'reason'  => 'no_data',
                    'message' => 'No QM interaction found for engagement.',
                ];
            }

            // The QM list endpoint nests interaction_id under interaction_info,
            // while older API revisions returned it at the top level. Check both
            // so the detail fetch works against either shape.
            $interactionId = $interactions[0]['interaction_info']['interaction_id']
                ?? $interactions[0]['interaction_id']
                ?? null;
            if ( $interactionId ) {
                $detailResponse = $this->requestWithBackoff( 'GET', "qm/interactions/{$interactionId}" );
                $detailData     = json_decode( $detailResponse->getBody(), true );
                return [
                    'status' => true,
                    'data'   => $detailData,
                ];
            }

            return [
                'status' => true,
                'data'   => $interactions[0],
            ];
        } catch ( \GuzzleHttp\Exception\BadResponseException $e ) {
            return [
                'status'      => false,
                'reason'      => 'api_error',
                'http_status' => $e->getResponse() ? $e->getResponse()->getStatusCode() : 0,
                'message'     => $e->getMessage(),
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'reason'  => 'api_error',
                'message' => $th->getMessage(),
            ];
        }
    }

    /**
     * Get QM automated evaluations for an interaction.
     * https://developers.zoom.us/docs/api/quality-management/
     * Requires scopes: zoom_quality_management:read:evaluations:admin
     *
     * Same status/reason contract as getQMInteraction.
     *
     * @param string $interactionId The QM interaction ID
     * @return array
     */
    public function getQMAutomatedEvaluations( string $interactionId ) {

        try {
            $response = $this->requestWithBackoff( 'GET', 'qm/automated_evaluations', [
                'query' => [
                    'interaction_id' => $interactionId,
                    'page_size'      => 10,
                ],
            ] );
            $data = json_decode( $response->getBody(), true );
            return [
                'status' => true,
                'data'   => $data,
            ];
        } catch ( \GuzzleHttp\Exception\BadResponseException $e ) {
            return [
                'status'      => false,
                'reason'      => 'api_error',
                'http_status' => $e->getResponse() ? $e->getResponse()->getStatusCode() : 0,
                'message'     => $e->getMessage(),
            ];
        } catch ( \Throwable $th ) {
            return [
                'status'  => false,
                'reason'  => 'api_error',
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
