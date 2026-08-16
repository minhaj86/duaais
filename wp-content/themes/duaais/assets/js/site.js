(function () {
	'use strict';

	const toggle = document.querySelector('.menu-toggle');
	const navigation = document.querySelector('[data-navigation]');

	if (!toggle || !navigation) {
		return;
	}

	const label = toggle.querySelector('.screen-reader-text');

	function setMenuState(isOpen) {
		toggle.setAttribute('aria-expanded', String(isOpen));
		navigation.classList.toggle('is-open', isOpen);
		document.body.classList.toggle('menu-open', isOpen);

		if (label) {
			label.textContent = isOpen ? 'Close menu' : 'Open menu';
		}
	}

	toggle.addEventListener('click', function () {
		setMenuState(toggle.getAttribute('aria-expanded') !== 'true');
	});

	navigation.addEventListener('click', function (event) {
		if (event.target.closest('a')) {
			setMenuState(false);
		}
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
			setMenuState(false);
			toggle.focus();
		}
	});

	document.addEventListener('click', function (event) {
		if (
			toggle.getAttribute('aria-expanded') === 'true' &&
			!navigation.contains(event.target) &&
			!toggle.contains(event.target)
		) {
			setMenuState(false);
		}
	});

	window.addEventListener('resize', function () {
		if (window.innerWidth > 1040) {
			setMenuState(false);
		}
	});
})();