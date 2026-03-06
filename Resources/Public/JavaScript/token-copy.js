(() => {
	const findRequiredElements = () => {
		const input = document.querySelector('[data-token-copy-input]');
		const button = document.querySelector('[data-token-copy-button]');
		const feedback = document.querySelector('[data-token-copy-feedback]');
		if (!(input instanceof HTMLInputElement) || !(button instanceof HTMLButtonElement)) {
			return null;
		}

		return {input, button, feedback};
	};

	const showFeedback = (feedbackElement) => {
		if (!(feedbackElement instanceof HTMLElement)) {
			return;
		}
		feedbackElement.hidden = false;
		window.setTimeout(() => {
			feedbackElement.hidden = true;
		}, 1800);
	};

	const copyWithFallback = (input) => {
		input.focus();
		input.select();
		input.setSelectionRange(0, input.value.length);
		document.execCommand('copy');
	};

	const copyToken = async (input, feedback) => {
		const token = input.value;
		if (token === '') {
			return;
		}

		try {
			if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
				await navigator.clipboard.writeText(token);
			} else {
				copyWithFallback(input);
			}
			showFeedback(feedback);
		} catch (_error) {
			copyWithFallback(input);
			showFeedback(feedback);
		}
	};

	const initialize = () => {
		const elements = findRequiredElements();
		if (elements === null) {
			return;
		}
		elements.button.addEventListener('click', () => {
			void copyToken(elements.input, elements.feedback);
		});
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initialize);
	} else {
		initialize();
	}
})();
