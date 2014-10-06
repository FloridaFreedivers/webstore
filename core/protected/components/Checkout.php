<?php

/**
 * A model class with static methods intended to facilitate logic required by the checkout process.
 *
 */

class Checkout
{
	/**
	 * Create the email notifications and save to the db.
	 *
	 * @param Cart/ShoppingCart $objCart
	 * @return void
	 * @throws Exception
	 */

	public static function emailReceipts($objCart)
	{
		if ($objCart instanceof Cart === false &&
			$objCart instanceof ShoppingCart == false)
		{
			Yii::log("Invalid Cart Object passed. Emails notifications not created", 'error', 'application.'.__CLASS__.".".__FUNCTION__);
			throw new Exception(
				Yii::t(
					'checkout',
					'Error creating email notifications, the cart was not valid.'
				)
			);
		}

		if (_xls_get_conf('EMAIL_SEND_CUSTOMER', 0) == 1)
		{
			$strHtmlBody = Yii::app()->controller->renderPartial(
				'/mail/_customerreceipt',
				array('cart' => $objCart),
				true
			);

			$strSubject = _xls_format_email_subject(
				'EMAIL_SUBJECT_CUSTOMER',
				$objCart->customer->first_name . ' ' . $objCart->customer->last_name,
				$objCart->id_str
			);

			$objEmail = new EmailQueue;

			$objEmail->customer_id = $objCart->customer_id;
			$objEmail->htmlbody = $strHtmlBody;
			$objEmail->cart_id = $objCart->id;
			$objEmail->subject = $strSubject;
			$objEmail->to = $objCart->customer->email;

			// If we get back false, it means conversion failed which 99.9% of
			// the time means improper HTML.
			$strPlain = strip_tags($strHtmlBody);
			if ($strPlain !== false)
			{
				$objEmail->plainbody = $strPlain;
			}

			$objEmail->save();
		}

		if (_xls_get_conf('EMAIL_SEND_STORE', 0) == 1)
		{
			$strHtmlBody = Yii::app()->controller->renderPartial(
				'/mail/_customerreceipt',
				array('cart' => $objCart),
				true
			);

			$strSubject = _xls_format_email_subject(
				'EMAIL_SUBJECT_OWNER',
				$objCart->customer->first_name . ' ' . $objCart->customer->last_name,
				$objCart->id_str
			);

			$objEmail = new EmailQueue;

			$objEmail->customer_id = $objCart->customer_id;
			$objEmail->htmlbody = $strHtmlBody;
			$objEmail->cart_id = $objCart->id;
			$objEmail->subject = $strSubject;
			$orderEmail = _xls_get_conf('ORDER_FROM','');
			$objEmail->to = empty($orderEmail) ? _xls_get_conf('EMAIL_FROM') : $orderEmail;

			// If we get back false, it means conversion failed which 99.9% of
			// the time means improper HTML.
			$strPlain = strip_tags($strHtmlBody);
			if ($strPlain !== false)
			{
				$objEmail->plainbody = $strPlain;
			}

			$objEmail->save();
		}
	}

	/**
	 * Final steps in completing cart and recalculating inventory numbers.
	 *
	 * If blnBehindTheScenes is true, it's an IPN-like transaction instead
	 * of the user session, so we don't do things like logout
	 *
	 * If ecp (executeCheckoutProcess, a function from the Checkout controller)
	 * is true, do not redirect to receipt.
	 *
	 * @param null ShoppingCart $objCart
	 * @param bool $blnBehindTheScenes
	 * @param bool $ecp
	 * @return void
	 */

	public static function finalizeCheckout($objCart = null, $blnBehindTheScenes = false, $ecp = false)
	{
		Yii::log("Finalizing checkout", 'info', 'application.'.__CLASS__.".".__FUNCTION__);
		if (!$objCart)
		{
			$objCart = Yii::app()->shoppingcart;
		}

		unset(Yii::app()->session['checkout.cache']);

		// Mark as successful order, ready to download.
		Yii::log("Marking as ".OrderStatus::AwaitingProcessing, 'info', 'application.'.__CLASS__.".".__FUNCTION__);
		$objCart->cart_type = CartType::order;
		$objCart->status = OrderStatus::AwaitingProcessing;
		$objCart->UpdateWishList(); //if we are supposed to delete anything
		$objCart->save();

		// Cart items get updated too.
		foreach ($objCart->cartItems as $item)
		{
			$item->cart_type = CartType::order;
			$item->save();
		}

		$objCart->RecalculateInventoryOnCartItems();
		$strLinkId = $objCart->linkid;

		$objCart->payment->markCompleted();

		if (!$blnBehindTheScenes)
		{
			//If we're behind a common SSL and we want to stay logged in
			if (Yii::app()->isCommonSSL && $objCart->customer->record_type != Customer::GUEST)
			{
				Yii::app()->user->setState('sharedssl', 1);
			}

			//If we were in as guest, immediately log out of guest account
			if ($objCart->customer->record_type == Customer::GUEST)
			{
				Yii::app()->user->logout();
			}

			//Redirect to our receipt, we're done
			Yii::app()->shoppingcart->releaseCart();
			Yii::log(
				"Redirecting to receipt, thank you for coming, exit through the gift shop.",
				'info',
				'application.'.__CLASS__.".".__FUNCTION__
			);

			if (!$ecp)
			{
				self::redirectToReceipt($strLinkId);
			}
		}
	}

	/**
	 * Redirect to the receipt page.
	 * TODO: make work with legacy checkout
	 *
	 * @param Cart->linkid $strLink
	 * @return void
	 */

	protected static function redirectToReceipt($strLink)
	{
		if (Yii::app()->theme->advancedCheckout === true)
		{
			$route = 'checkout/thankyou';
		}
		else
		{
			$route = 'cart/receipt';
		}

		if (Yii::app()->user->getState('sharedssl') && Yii::app()->isCommonSSL)
		{
			Yii::app()->user->setState('cartid', null);

			//If we have created a login on checkout that should survive, route through login first
			//on original URL. Otherwise, we can just to straight to the receipt
			if (Yii::app()->user->getState('createdoncheckout') == 1)
			{
				Yii::app()->user->setState('createdoncheckout', 0); //In case we submit on the same login later

				$strIdentity = Yii::app()->user->id.",0,cart,receipt,".$strLink;
				Yii::log('Routing to receipt via common login: '.$strIdentity, 'info', 'application.'.__CLASS__.".".__FUNCTION__);
				$redirString = _xls_encrypt($strIdentity);

				$url = Yii::app()->controller->createAbsoluteUrl(
					'commonssl/sharedsslreceive',
					array('link' => $redirString)
				);
			}
			else
			{
				$url = Yii::app()->controller->createAbsoluteUrl(
					$route,
					array('getuid' => $strLink)
				);
			}

			$url = _xls_url_common_to_custom($url);

			Yii::app()->controller->redirect($url);
			return;
		}

		if (isset($_POST['noredirect']))
		{
			return;
		}

		Yii::app()->controller->redirect(
			Yii::app()->controller->createAbsoluteUrl(
				$route,
				array('getuid' => $strLink)
			)
		);
	}
}