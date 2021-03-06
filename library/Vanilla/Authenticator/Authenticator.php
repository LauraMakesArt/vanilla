<?php
/**
 * @author Alexandre (DaazKu) Chouinard <alexandre.c@vanillaforums.com>
 * @copyright 2009-2018 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GNU GPL v2
 */

namespace Vanilla\Authenticator;

use Exception;
use Garden\Schema\Schema;
use Garden\Schema\ValidationException;
use Garden\Schema\ValidationField;
use Garden\Web\RequestInterface;

abstract class Authenticator {

    /**
     * Identifier of this authenticator instance.
     *
     * If the authenticator {@link isUnique()} the ID should match the {@link getType}.
     *
     * Extending classes will most likely require to have a dependency on RequestInterface so that they can
     * fetch the ID from the URL and throw an exception if it is not found or invalid.
     *
     * @var string
     */
    private $authenticatorID;

    /** @var bool Determine if this authenticator is active or not. */
    private $active = true;

    /**
     * Authenticator constructor.
     *
     * @throws Exception
     * @throws ValidationException
     * @param string $authenticatorID
     */
    public function __construct($authenticatorID) {
        if (static::isUnique() && $authenticatorID !== self::getType()) {
            throw new \Exception('Unique Authenticators must have getID() === getType()');
        }

        $this->authenticatorID = $authenticatorID;

        $classParts = explode('\\', static::class);
        if (array_pop($classParts) !== static::getTypeImpl().'Authenticator') {
            throw new \Exception('Authenticator class name must end with "Authenticator".');
        }

        // Let's validate ourselves :)
        $this->getAuthenticatorInfo();
    }

    /**
     * Get this authenticate Schema.
     *
     * @return Schema
     */
    public static function getAuthenticatorSchema(): Schema {
        return self::getAuthenticatorTypeSchema()->merge(
            Schema::parse([
                'authenticatorID:s' => 'Authenticator instance\'s identifier.',
                'type' => null,
                'name' => null,
                'signInUrl:s|n' => 'The configured relative sign in URL of the provider.',
                'registerUrl:s|n' => 'The configured relative register URL of the provider.',
                'signOutUrl:s|n' => 'The configured relative sign out URL of the provider.',
                'ui:o' => static::getUiSchema(),
                'isActive:b' => 'Whether or not the Authenticator can be used.',
                'isUnique' => null,
                'attributes:o' => 'Provider specific attributes',
            ])
        );
    }

    /**
     * Information on this type of authenticator.
     * Check {@link getAuthenticatorTypeSchema()} to know what is returned by this function.
     *
     * @throws ValidationException
     * @return array
     */
    final public static function getAuthenticatorTypeInfo(): array {
        $defaults = static::getAuthenticatorTypeDefaultInfo();
        $info = static::getAuthenticatorTypeInfoImpl();

        return static::getAuthenticatorTypeSchema()->validate($info + $defaults);
    }

    /**
     * Return authenticator type default information.
     *
     * This method is intended to fill information so that child classes won't have to do it.
     * Use {@link getAuthenticatorTypeInfoImpl()} to fill the "final" information.
     *
     * @return array
     */
    protected static function getAuthenticatorTypeDefaultInfo(): array {
        $type =  static::getType();
        return [
            'type' => $type,
            'name' => ucfirst($type),
            'isUnique' => static::isUnique(),

        ];
    }

    /**
     * {@link getAuthenticatorTypeInfo} implementation.
     *
     * Must be returned by this method:
     * - ui.photoUrl
     * - ui.backgroundColor
     * - ui.foregroundColor
     *
     * Any fields from {@link getAuthenticatorTypeSchema()} can be overridden from this method.
     *
     * @return array
     */
    abstract protected static function getAuthenticatorTypeInfoImpl(): array;

    /**
     * Get the authenticator type schema.
     *
     * @return Schema
     */
    public static function getAuthenticatorTypeSchema(): Schema {
        return Schema::parse([
            'type:s' => 'Authenticator instance\'s type.',
            'name:s' => 'User friendly name of the authenticator.',
            'ui:o' => Schema::parse([
                    'photoUrl' => null,
                    'backgroundColor' => null,
                    'foregroundColor' => null,
                ])
                ->addValidator('backgroundColor', self::getCSSColorValidator())
                ->addValidator('foregroundColor', self::getCSSColorValidator())
                ->add(static::getUiSchema())
            ,
            'isUnique:b' => 'Whether this authenticator can have multiple instances or not. Unique authenticators have authenticatorID equal to their type.',
        ]);
    }

    /**
     * Getter of type.
     *
     * @return string
     */
    final public static function getType() {
        return static::getTypeImpl();
    }

    /**
     * Default {@link getType} implementation.
     *
     * @return string
     */
    private static function getTypeImpl() {
        // return Type from "{Type}Authenticator"
        $classParts = explode('\\', static::class);
        return (string)substr(array_pop($classParts), 0, -strlen('Authenticator'));
    }

    /**
     * Get the authenticator UI information schema.
     *
     * @return Schema
     */
     public static function getUiSchema(): Schema {
        return Schema::parse([
                'url:s' => 'Local relative URL from which you can initiate the SignIn process with this authenticator',
                'buttonName:s' => 'The display text to put in the button. Ex: "Sign in with Facebook"',
                'photoUrl:s|n' => 'The icon URL for the button.',
                'backgroundColor:s|n' => 'A css color code for the background. (Hex color, rgb or rgba)',
                'foregroundColor:s|n' => 'A css color code for the foreground. (Hex color, rgb or rgba)',
            ])
            ->addValidator('backgroundColor', self::getCSSColorValidator())
            ->addValidator('foregroundColor', self::getCSSColorValidator())
        ;
    }

    /**
     * The validation function used to validate UI CSS colors.
     *
     * @return \Closure
     */
    protected static function getCSSColorValidator() {
        return function($data, ValidationField $field) {
            if ($data === null) {
                return true;
            }

            $rgbaValuesValidator = function ($matches) {
                foreach ($matches as $index => $value) {
                    if ($index === 0) {
                        continue;
                    }
                    if ($index === 4) {
                        // It's an rgba. If this value is valid then everything is valid.
                        return $value === '0' || $value === '1' || preg_match('/^0.\d{1,2}$/', $value);
                    }
                    if (filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 255]]) === false) {
                        return false;
                    }
                }

                // It's an rgb and everything matched. Let's just "make sure" by ensuring that there was 4 matches.
                return count($matches) === 4;
            };

            if (preg_match('/^#(?:[0-9A-F]{3}){1,2}$/i', $data)) {
                $valid = true;
            } else {
                if (preg_match('/^rgb\((\d+),\s?(\d+),\s?(\d+)\)$/', $data, $matches) || preg_match('/^rgba\((\d+),\s?(\d+),\s?(\d+),\s?([^\s)]+?)\)$/', $data, $matches)) {
                    $valid = $rgbaValuesValidator($matches);
                } else {
                    $valid = false;
                }
            }

            if (!$valid) {
                $field->addError('invalidCssColor', [
                    'messageCode' => 'The css color must match of one of the following format: #rgb, #rrggbb, rgb(r, g, b) or rgba(r, g, b, a.00)',
                ]);
            }

            return $valid;
        };
    }

    /**
     * Tell whether this type of authenticator can have multiple instance or not.
     *
     * @return bool
     */
    abstract public static function isUnique(): bool;

    /**
     * Getter of active.
     *
     * @return bool
     */
    public function isActive(): bool {
        return $this->active;
    }

    /**
     * Setter of active.
     *
     * @param bool $active
     * @return $this
     */
    public function setActive(bool $active) {
        $this->active = $active;

        return $this;
    }

    /**
     * Return authenticator default information.
     *
     * This method is intended to fill information so that child classes won't have to do it.
     * Use {@link getAuthenticatorInfoImpl()} to fill the "final" information.
     *
     * @return array
     */
    protected function getAuthenticatorDefaultInfo(): array {
        return [
            'authenticatorID' => $this->getID(),
            'signInUrl' => $this->getSignInUrl(),
            'registerUrl' => $this->getRegisterUrl(),
            'signOutUrl' => $this->getSignOutUrl(),
            'ui' => [
                'url' => strtolower(url('/authenticate/signin/'.static::getType().'/'.$this->getID())),
            ],
            'isActive' => $this->isActive(),
            'attributes' => [],
        ];
    }

    /**
     * Get all the authenticator information.
     *
     * @throws ValidationException
     * @return array
     */
    final public function getAuthenticatorInfo(): array {
        $defaults = $this->getAuthenticatorDefaultInfo();
        $instanceInfo = $this->getAuthenticatorInfoImpl();
        $typeInfo = static::getAuthenticatorTypeInfo();

        return static::getAuthenticatorSchema()->validate(array_replace_recursive($defaults, $instanceInfo, $typeInfo));

    }

    /**
     * {@link getAuthenticatorInfo} implementation.
     *
     * Must be returned by this method:
     * - ui.buttonName
     *
     * Any fields from {@link getAuthenticatorSchema()}, but fields from {@link getAuthenticatorTypeInfo()}, can be overridden from this method.
     *
     * @return array
     */
    abstract protected function getAuthenticatorInfoImpl(): array;

    /**
     * Getter of the authenticator's ID.
     *
     * @return string
     */
    final public function getID(): string {
        return $this->authenticatorID;
    }

    /**
     * Returns the relative register in URL.
     *
     * @return string|null
     */
    abstract public function getRegisterUrl();

    /**
     * Returns the relative sign in URL.
     *
     * @return string|null
     */
    abstract public function getSignInUrl();

    /**
     * Returns the relative sign out URL.
     *
     * @return string|null
     */
    abstract public function getSignOutUrl();

    /**
     * Validate an authentication by using the request's data.
     *
     * @throws Exception Reason why the authentication failed.
     * @param RequestInterface $request
     * @return mixed The user's information.
     */
    final public function validateAuthentication(RequestInterface $request) {
         if (!$this->isActive()) {
             throw new Exception('Cannot authenticate with an inactive authenticator.');
         }

         return $this->validateAuthenticationImpl($request);
     }

    /**
     * {@link ValidateAuthentication} implementation.
     *
     * @throws Exception Reason why the authentication failed.
     * @param RequestInterface $request
     * @return array The user's information.
     */
     abstract public function validateAuthenticationImpl(RequestInterface $request);
}
