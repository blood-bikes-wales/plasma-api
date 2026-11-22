<?php

namespace App\Models;

/**
 * Backwards-compatible name for a delivery leg (allocatable hop).
 *
 * Prefer {@see DeliveryLeg} / {@see DeliveryRequest} in new code. Route binding may resolve
 * either a leg or a request; controllers that still type-hint DeliveryJob only receive legs.
 */
class DeliveryJob extends DeliveryLeg {}
