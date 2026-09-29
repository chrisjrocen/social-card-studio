<?php
/**
 * Marks the end of an endpoint response under test.
 *
 * @package ChrxDigital\SocialCardStudio
 */

declare( strict_types=1 );

namespace ChrxDigital\SocialCardStudio\Delivery;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown instead of exit() when SCSTUDIO_TESTING is defined.
 *
 * The endpoint's contract is "respond and stop", and every branch of it ends in a
 * status code plus headers. A test cannot assert on a branch that calls exit(), so
 * under test the stop becomes a throw — the same control flow, observable.
 *
 * Never thrown in production: the constant is only ever defined by the test harness.
 *
 * @since 0.1.0
 */
final class EndpointFinished extends \RuntimeException {}
