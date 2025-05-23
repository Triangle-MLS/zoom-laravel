<?php
namespace Jubaer\Zoom;

use GuzzleHttp\Client;

class Zoom {

    protected string $accessToken;
    protected Client $client;

    public function __construct( protected $account_id = null, protected $client_id = null, protected $client_secret = null ) {

        if ( auth()->check() ) {
            $user                = auth()->user();
            $this->client_id     = method_exists( $user, 'clientID' ) ? $user->clientID() : config( 'zoom.client_id' );
            $this->client_secret = method_exists( $user, 'clientSecret' ) ? $user->clientSecret() : config( 'zoom.client_secret' );
            $this->account_id    = method_exists( $user, 'accountID' ) ? $user->accountID() : config( 'zoom.account_id' );
        }
        else {
            $this->client_id     = config( 'zoom.client_id' );
            $this->client_secret = config( 'zoom.client_secret' );
            $this->account_id    = config( 'zoom.account_id' );
        }

        $this->accessToken = $this->getAccessToken();

        $this->client = new Client( [
            'base_uri' => 'https://api.zoom.us/v2/',
            'headers'  => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
            ],
        ] );
    }

    protected function getAccessToken() {

        $client = new Client( [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
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
     * retrieve engagement
     * @param string $meetingId
     * @return array
     */
    public function getEngagements() {

        $error_count       = 0;
        $getEngagementsAPI = function (string $next_page_token = null) use (&$error_count) {
            try {
                $response = $this->client->request( 'GET', "contact_center/engagements?page_size=100" . ( ( $next_page_token === null ) ? '' : '&next_page_token=' . $next_page_token ) );
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
        $next_page_token   = null;
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
            $response = $this->client->request( 'GET', "contact_center/engagements/$engagementId" );
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

}
