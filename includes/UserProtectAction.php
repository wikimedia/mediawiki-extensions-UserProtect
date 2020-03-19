<?php

class UserProtectAction extends Action {

	/**
	 * @inheritDoc
	 */
	public function getName() {
		return 'userprotect';
	}

	/**
	 * @inheritDoc
	 */
	public function show() {
		$form = new UserProtectForm( $this );
		$form->execute();
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites() {
		return true;
	}
}
