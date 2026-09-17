<?php
/**
 * One control a surface can render.
 *
 * Surfaces decide how to draw a control; they do not decide which controls
 * exist or whether one applies. That lives in the Catalog.
 *
 * @package PageBuilderCacheControl
 */

declare( strict_types=1 );

namespace PageBuilderCacheControl\Controls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Control {

	/** Acts on a cache provider. */
	public const TYPE_PURGE = 'purge';

	/** Mints an uncached-preview link for another browser session. */
	public const TYPE_PREVIEW = 'preview';

	public string $id;

	public string $type;

	public string $label;

	public string $icon;

	public function __construct( string $id, string $type, string $label, string $icon ) {
		$this->id    = $id;
		$this->type  = $type;
		$this->label = $label;
		$this->icon  = $icon;
	}

	/**
	 * Shape handed to JavaScript.
	 *
	 * @return array<string,string>
	 */
	public function to_array(): array {
		return array(
			'id'    => $this->id,
			'type'  => $this->type,
			'label' => $this->label,
			'icon'  => $this->icon,
		);
	}
}
