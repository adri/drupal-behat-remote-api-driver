<?php

namespace Kirschbaum\DrupalBehatRemoteAPIDriver\HttpClient\Listener;

use Kirschbaum\DrupalBehatRemoteAPIDriver\Exception\TwoFactorAuthenticationRequiredException;
use Kirschbaum\DrupalBehatRemoteAPIDriver\HttpClient\Message\ResponseMediator;
use Guzzle\Common\Event;
use Guzzle\Http\Message\Response;

use Kirschbaum\DrupalBehatRemoteAPIDriver\Exception\ApiLimitExceedException;
use Kirschbaum\DrupalBehatRemoteAPIDriver\Exception\ErrorException;
use Kirschbaum\DrupalBehatRemoteAPIDriver\Exception\RuntimeException;
use Kirschbaum\DrupalBehatRemoteAPIDriver\Exception\ValidationFailedException;

/**
 * @author Joseph Bielawski <stloyd@gmail.com>
 */
class ErrorListener
{
    /**
     * @var array
     */
    private $options;

    /**
     * @param array $options
     */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    /**
     * {@inheritDoc}
     */
    public function onRequestError(Event $event)
    {
        /** @var $request \Guzzle\Http\Message\Request */
        $request = $event['request'];
        $response = $request->getResponse();

        if ($response->isClientError() || $response->isServerError()) {
            $remaining = (string) $response->getHeader('X-RateLimit-Remaining');

            if (null != $remaining && 1 > $remaining && 'rate_limit' !== substr($request->getResource(), 1, 10)) {
                throw new ApiLimitExceedException($this->options['api_limit']);
            }

            if (401 === $response->getStatusCode()) {
                if ($response->hasHeader('X-SauceLabs-OTP') && 0 === strpos((string) $response->getHeader('X-SauceLabs-OTP'), 'required;')) {
                    $type = substr((string) $response->getHeader('X-SauceLabs-OTP'), 9);

                    throw new TwoFactorAuthenticationRequiredException($type);
                }
            }

            $content = ResponseMediator::getContent($response);
            if (is_array($content) && isset($content['message'])) {
                if (400 == $response->getStatusCode()) {
                    throw new ErrorException($content['message'], 400);
                } elseif (422 == $response->getStatusCode() && isset($content['errors'])) {
                    $errors = array();
                    foreach ($content['errors'] as $error) {
                        switch ($error['code']) {
                            case 'missing':
                                $errors[] = sprintf('The %s %s does not exist, for resource "%s"', $error['field'], $error['value'], $error['resource']);
                                break;

                            case 'missing_field':
                                $errors[] = sprintf('Field "%s" is missing, for resource "%s"', $error['field'], $error['resource']);
                                break;

                            case 'invalid':
                                $errors[] = sprintf('Field "%s" is invalid, for resource "%s"', $error['field'], $error['resource']);
                                break;

                            case 'already_exists':
                                $errors[] = sprintf('Field "%s" already exists, for resource "%s"', $error['field'], $error['resource']);
                                break;

                            default:
                                $errors[] = $error['message'];
                                break;

                        }
                    }

                    throw new ValidationFailedException('Validation Failed: ' . implode(', ', $errors), 422);
                }
            }

            if(isset($content['error']))
                $content = $content['error'];
            throw new RuntimeException(isset($content['message']) ? $content['message'] : $content, $response->getStatusCode());
        };
    }
}