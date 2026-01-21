(function() {
	function initPoweredBy() {
		const container = document.createElement('a');
		container.className = 'sg-powered-by';
		container.href = 'https://www.sgalinski.de';
		container.target = '_blank';
		container.rel = 'noopener noreferrer';
		container.innerHTML = '<span>powered by sg_apicore</span><img src="' + window.sgApiCoreLogoPath + '" alt="sgalinski Logo">';

		document.body.appendChild(container);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initPoweredBy);
	} else {
		initPoweredBy();
	}
})();
