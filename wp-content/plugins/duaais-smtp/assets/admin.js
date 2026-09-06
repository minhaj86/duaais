( function () {
	'use strict';

	const mailer = document.getElementById( 'duaais-smtp-mailer' );
	const host = document.getElementById( 'duaais-smtp-host' );
	const port = document.getElementById( 'duaais-smtp-port' );
	const encryption = document.getElementById( 'duaais-smtp-encryption' );
	const autoTls = document.getElementById( 'duaais-smtp-auto-tls' );
	const authentication = document.getElementById( 'duaais-smtp-authentication' );
	const authRows = document.querySelectorAll( '[data-duaais-auth-row]' );
	const help = document.querySelectorAll( '[data-duaais-mailer-help]' );

	if ( ! mailer || ! host || ! port || ! encryption || ! autoTls || ! authentication || ! window.duaaisSmtpAdmin ) {
		return;
	}

	let activeMailer = mailer.value;
	let customSettings = {
		host: host.value,
		port: port.value,
		encryption: encryption.value,
		autoTls: autoTls.checked,
		authentication: authentication.checked
	};

	function updateAuthRows() {
		authRows.forEach( function ( row ) {
			row.hidden = ! authentication.checked;
		} );
	}

	function updateMailer() {
		if ( 'smtp' === activeMailer ) {
			customSettings = {
				host: host.value,
				port: port.value,
				encryption: encryption.value,
				autoTls: autoTls.checked,
				authentication: authentication.checked
			};
		}

		const selected = mailer.value;
		const preset = window.duaaisSmtpAdmin.presets[ selected ];

		if ( preset ) {
			host.value = preset.host;
			port.value = preset.port;
			encryption.value = preset.encryption;
			autoTls.checked = Boolean( preset.auto_tls );
			authentication.checked = Boolean( preset.authentication );
		} else if ( 'smtp' === selected && 'smtp' !== activeMailer ) {
			host.value = customSettings.host;
			port.value = customSettings.port;
			encryption.value = customSettings.encryption;
			autoTls.checked = customSettings.autoTls;
			authentication.checked = customSettings.authentication;
		}

		host.readOnly = Boolean( preset );
		port.readOnly = Boolean( preset );
		encryption.disabled = Boolean( preset );
		autoTls.disabled = Boolean( preset );
		authentication.disabled = Boolean( preset );

		help.forEach( function ( paragraph ) {
			paragraph.hidden = paragraph.dataset.duaaisMailerHelp !== selected;
		} );

		activeMailer = selected;
		updateAuthRows();
	}

	mailer.addEventListener( 'change', updateMailer );
	authentication.addEventListener( 'change', updateAuthRows );
	updateMailer();
}() );
