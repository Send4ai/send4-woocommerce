<?php
/**
 * The outcome of one order-event POST, in the vocabulary the queue cares about.
 *
 * @package Send4WC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Value object returned by Send4WC_Api_Client::send_store_event().
 */
class Send4WC_Api_Result {

	/** Accepted and routed to a flow. */
	const DELIVERED = 'delivered';

	/** Send4 had already received this order at this status. */
	const DUPLICATE = 'duplicate';

	/** Accepted, but no flow starts on this status (it may still have ended runs). Final. */
	const UNROUTED = 'unrouted';

	/** Rejected for good: a malformed event or a credential that will never work. */
	const PERMANENT = 'permanent';

	/** Send4 was unreachable or unhappy in a way that a later retry may fix. */
	const TRANSIENT = 'transient';

	/**
	 * Which of the five outcomes this is.
	 *
	 * @var string
	 */
	public $outcome;

	/**
	 * HTTP status code, 0 when the request never completed.
	 *
	 * @var int
	 */
	public $code;

	/**
	 * Flow run id, when Send4 started one.
	 *
	 * @var int|null
	 */
	public $run_id;

	/**
	 * Human readable detail for the dashboard. PII-free.
	 *
	 * @var string
	 */
	public $message;

	/**
	 * Seconds Send4 asked us to wait before trying again (a 429's Retry-After),
	 * 0 when it did not say.
	 *
	 * @var int
	 */
	public $retry_after;

	/**
	 * True when Send4 is up and only this order is busy: the run its event
	 * ends was answering the buyer. The rows behind it are not affected, so
	 * the batch carries on.
	 *
	 * @var bool
	 */
	public $order_busy = false;

	/**
	 * Constructor.
	 *
	 * @param string   $outcome     One of the class constants.
	 * @param int      $code        HTTP status code.
	 * @param string   $message     Human readable detail.
	 * @param int|null $run_id      Flow run id when present.
	 * @param int      $retry_after Seconds to wait before retrying, 0 for "no opinion".
	 */
	public function __construct( $outcome, $code = 0, $message = '', $run_id = null, $retry_after = 0 ) {
		$this->outcome     = $outcome;
		$this->code        = (int) $code;
		$this->message     = (string) $message;
		$this->run_id      = null === $run_id ? null : (int) $run_id;
		$this->retry_after = max( 0, (int) $retry_after );
	}

	/**
	 * True when the event reached Send4 and needs no retry.
	 *
	 * @return bool
	 */
	public function is_final() {
		return in_array( $this->outcome, array( self::DELIVERED, self::DUPLICATE, self::UNROUTED ), true );
	}

	/**
	 * True when a later attempt could still succeed.
	 *
	 * @return bool
	 */
	public function is_retryable() {
		return self::TRANSIENT === $this->outcome;
	}
}
