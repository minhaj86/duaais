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

(function () {
	'use strict';

	const viewer = document.querySelector('[data-constitution-viewer]');

	if (!viewer) {
		return;
	}

	const frame = viewer.querySelector('.constitution-frame');
	const options = viewer.querySelectorAll('[data-constitution-src]');

	if (!frame || !options.length) {
		return;
	}

	options.forEach(function (option) {
		option.addEventListener('click', function () {
			const source = option.dataset.constitutionSrc;
			const title = option.dataset.constitutionTitle;

			if (!source || !title) {
				return;
			}

			frame.setAttribute('src', source);
			frame.setAttribute('title', title);

			options.forEach(function (candidate) {
				const isActive = candidate === option;

				candidate.classList.toggle('is-active', isActive);
				candidate.setAttribute('aria-pressed', String(isActive));
			});
		});
	});
})();