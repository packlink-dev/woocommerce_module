<?php
/**
 * Packlink PRO Shipping WooCommerce Integration.
 *
 * @package Packlink
 */

use Packlink\WooCommerce\Controllers\Packlink_Frontend_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Login failure flag.
 *
 * @var bool $login_failure
 */
/**
 * Packlink frontend controller.
 *
 * @var Packlink_Frontend_Controller $this
 */

$data = $this->resolve_view_arguments();

?>

<div class="pl-login-page" id="pl-main-page-holder">
	<div class="pl-login-page-side-img-wrapper pl-collapse">
		<img src="<?php echo $data['dashboard_logo']; ?>" class="pl-login-icon"
			 alt="<?php echo __( 'Packlink PRO Shipping', 'packlink-pro-shipping' ); ?>"/>
	</div>
	<div class="pl-login-page-content-wrapper">
		<div class="pl-register-form-wrapper">
			<div class="pl-register-btn-section-wrapper">
				<?php echo __( 'Don\'t have an account?', 'packlink-pro-shipping' ); ?>
				<button type="button" id="pl-register-btn" class="button button-primary button-register">
					<?php echo __( 'Register', 'packlink-pro-shipping' ); ?>
				</button>
			</div>
			<div class="pl-register-country-section-wrapper" id="pl-register-form" style="display: none;">
				<div class="pl-register-form-close-btn">
					<svg id="pl-register-form-close-btn" width="24" height="24" viewBox="0 0 22 22"
						 xmlns="http://www.w3.org/2000/svg">
						<g fill="none" fill-rule="evenodd">
							<path d="M11 21c5.523 0 10-4.477 10-10S16.523 1 11 1 1 5.477 1 11s4.477 10 10 10zm0 1C4.925 22 0 17.075 0 11S4.925 0 11 0s11 4.925 11 11-4.925 11-11 11z"
								  fill="#2095F2" fill-rule="nonzero"/>
							<path d="M7.5 7.5l8 7M15.5 7.5l-8 7" stroke="#2095F2" stroke-linecap="square"/>
						</g>
					</svg>
				</div>
				<div class="pl-register-country-title-wrapper">
					<?php echo __( 'Select country to start', 'packlink-pro-shipping' ); ?>
				</div>
				<div class="pl-register-country-list-wrapper">
					<?php foreach ( $data['countries'] as $country ) { ?>
						<a href="<?php echo $country->registrationLink ?>" target="_blank">
							<div class="pl-country">
								<img
										class="pl-country-logo"
										src="<?php echo $data['image_base']; ?>flags/<?php echo $country->code ?>.svg"
										alt="<?php echo $country->name ?>"
								>
								<div class="pl-country-name"><?php echo $country->name ?></div>
							</div>
						</a>
					<?php } ?>
				</div>
			</div>
		</div>
		<div>
			<div class="pl-login-form-header">
				<div class="pl-login-form-title-wrapper">
					<?php echo __( 'Allow WooCommerce to connect to PacklinkPRO', 'packlink-pro-shipping' ); ?>
				</div>
				<div class="pl-login-form-text-wrapper">
					<?php echo __( 'Your API key can be found under', 'packlink-pro-shipping' ); ?>
					pro.packlink/<strong>Settings/PacklinkProAPIkey</strong>
				</div>
			</div>
			<?php if ( $login_failure ) : ?>
				<p class="pl-login-error-msg"><?php echo __( 'API key was incorrect.', 'packlink-pro-shipping' ); ?></p>
			<?php endif; ?>
			<div class="pl-login-form-label-wrapper">
				<?php echo __( 'Connect your account', 'packlink-pro-shipping' ); ?>
			</div>
			<form method="POST">
				<div class="pl-login-form-wrapper">
					<fieldset class="form-group pl-form-section-input pl-text-input">
						<input type="text" class="form-control" id="pl-login-api-key" name="api_key" required/>
						<span class="pl-text-input-label"><?php echo __( 'Api key', 'packlink-pro-shipping' ); ?></span>
					</fieldset>
				</div>
				<div>
					<button type="submit" name="login"
							class="button button-primary button-login"><?php echo __( 'Log in', 'packlink-pro-shipping' ); ?></button>
				</div>
			</form>
		</div>
	</div>
</div>

<script type="application/javascript">
	/**
	 * Initializes register form on login page.
	 */
	function initRegisterForm() {
		let registerBtnClicked = function (event) {
			event.stopPropagation();
			let form = document.getElementById('pl-register-form');
			form.style.display = 'block';

			let closeBtn = document.getElementById('pl-register-form-close-btn');
			closeBtn.addEventListener('click', function () {
				form.style.display = 'none';
			});

			let container = document.querySelector('.pl-login-page-content-wrapper');
			container.addEventListener('click', function () {
				form.style.display = 'none';
			});
		};

		let btn = document.getElementById('pl-register-btn');
		btn.addEventListener('click', registerBtnClicked, true);
	}

	document.addEventListener('DOMContentLoaded', function () {
		Packlink.utilityService.configureInputElements();
	});

	/**
	 * Calculates content height.
	 *
	 * Footer can be dynamically hidden or displayed by WooCommerce,
	 * so we have to periodically recalculate content height.
	 *
	 * @param {number} offset
	 */
	function calculateContentHeight(offset) {
		if (typeof offset === 'undefined') {
			offset = 0;
		}

		let localOffset = offset;

		let footer = document.getElementById('wpfooter');
		let wpBody = document.getElementById('wpbody-content');
		if (footer) {
			localOffset += footer.clientHeight;
		}

		let alerts = document.getElementsByClassName('alert');

		for (let alert of alerts) {
			if (alert.clientHeight) {
				localOffset += 71;
			}
		}

		let content = document.getElementById('pl-main-page-holder');
		content.style.height = `calc(100% - ${localOffset}px`;
		wpBody.style.height = `calc(100% - ${localOffset}px`;

		setTimeout(calculateContentHeight, 250, offset);
	}

	initRegisterForm();
	calculateContentHeight(20);
</script>
