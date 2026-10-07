<?php

namespace MailerLiteForms\Api;

class Client
{
    private $url;
    private $headers;

    /**
     * Client constructor
     *
     * @access      public
     * @return      void
     * @since       1.5.0
     */
    public function __construct($url, $headers)
    {

        $this->url = $url;
        $this->headers = $headers;
    }

    /**
     * Client for GET requests
     *
     * @access      public
     * @since       1.5.0
     */
    public function remote_get($endpoint, $args = [])
    {

        $args['body'] = $args;
        $args['headers'] = $this->headers;
        $args['user-agent'] = $this->userAgent();

        return wp_remote_get($this->url.$endpoint, $args);
    }

    /**
     * Client for POST requests
     *
     * @access      public
     * @since       1.5.0
     */
    public function remote_post($endpoint, $args = [])
    {

        $params = [];
        $params['headers'] = $this->headers;
        $params['body'] = json_encode( $args );
        $params['user-agent'] = $this->userAgent();

        return wp_remote_post($this->url.$endpoint, $params);
    }

    private function userAgent()
    {
        global $wp_version;

        return 'MailerLite Forms/' . MAILERLITE_VERSION . ' (WP/' . $wp_version . ')';
    }
}