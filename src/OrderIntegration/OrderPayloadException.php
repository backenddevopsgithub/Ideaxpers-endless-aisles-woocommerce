<?php
namespace IdeaXperts\EndlessAisles\OrderIntegration;

defined( 'ABSPATH' ) || exit;

/** A vendor line exists but the order cannot be sent; the message is a stable failure code. */
final class OrderPayloadException extends \RuntimeException {}
