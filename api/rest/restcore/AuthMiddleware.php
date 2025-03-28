<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * A webservice interface to Mantis Bug Tracker
 *
 * @package MantisBT
 * @copyright Copyright MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 */

require_api( 'authentication_api.php' );
require_api( 'user_api.php' );

/**
 * A middleware class that handles authentication and authorization to access APIs.
 */
class AuthMiddleware {
	public function __invoke( \Slim\Http\Request $request, \Slim\Http\Response $response, callable $next ) {

		# Check if user is authenticated by API token
		$t_authorization_header = $request->getHeaderLine( HEADER_AUTHORIZATION );
		if( !empty( $t_authorization_header ) ) {
			# TODO: add an index on the token hash for the method below

			# Manage multiple authorization header (ex: Basic + token)
			$t_authorization_headers = explode(', ', $t_authorization_header);

			# Search for the token among the different authorization headers.
			foreach( $t_authorization_headers as $t_api_token ) {
				$t_user_id = api_token_get_user( $t_api_token );
				if( $t_user_id !== false ) {
					# Valid token found
					$t_username = user_get_username( $t_user_id );
					$t_password = $t_api_token;
					$t_login_method = LOGIN_METHOD_API_TOKEN;
					break;
				}
			}
		}

		if( !isset( $t_login_method ) ) {
			# Check if user is authenticated by cookie
			# This mode is used when Web UI javascript calls into the API.
			if( auth_is_user_authenticated() ) {
				$t_username = user_get_username( auth_get_current_user_id() );
				$t_password = auth_get_current_user_cookie( /* auto-login-anonymous */ false );
				$t_login_method = LOGIN_METHOD_COOKIE;
			} else {
				$t_username = auth_anonymous_account();
				$t_password = '';
				$t_login_method = LOGIN_METHOD_ANONYMOUS;
				if( !auth_anonymous_enabled() || empty( $t_username ) ) {
					return $response->withStatus( HTTP_STATUS_UNAUTHORIZED, 'Valid API token required' );
				}
			}
		}

		if( mci_check_login( $t_username, $t_password ) === false ) {
			return $response->withStatus( HTTP_STATUS_FORBIDDEN, 'Access denied' );
		}

		$t_impersonation_header = $request->getHeaderLine( HEADER_USERNAME );
		if( !empty( $t_impersonation_header ) && $t_login_method == LOGIN_METHOD_API_TOKEN ) {
			$t_username = $t_impersonation_header;
			$t_impersonation_user_id = user_get_id_by_name( $t_username );
			if( $t_impersonation_user_id === false ) {
				return $response->withStatus( HTTP_STATUS_NOT_FOUND, 'Invalid impersonation username' );
			}

			if( $t_impersonation_user_id === auth_get_current_user_id() ) {
				return $response->withStatus( HTTP_STATUS_FORBIDDEN, "Can't impersonate self" );
			}

			if( !access_has_global_level( config_get( 'impersonate_user_threshold' ) ) ) {
				return $response->withStatus( HTTP_STATUS_FORBIDDEN, "User can't impersonate other users" );
			}

			if( (int)user_get_field( $t_impersonation_user_id, 'access_level' ) > current_user_get_field( 'access_level' ) ) {
				return $response->withStatus( HTTP_STATUS_FORBIDDEN, "User can't impersonate users with higher access level" );
			}

			# Token is valid, then login the user without worrying about a password.
			if( auth_attempt_script_login( $t_username, null ) === false ) {
				return $response->withStatus( HTTP_STATUS_FORBIDDEN, 'Login failed for impersonated user' );
			}

			# Set language to user's language
			lang_push( lang_get_default() );
		}

		# Now that user is logged in, check if they have the right access level to access the REST API.
		# Don't treat web UI calls with cookies as API calls that need to be disabled for certain access levels.
		if( $t_login_method != LOGIN_METHOD_COOKIE && !mci_has_readonly_access() ) {
			return $response->withStatus( HTTP_STATUS_FORBIDDEN, 'Higher access level required for API access' );
		}

		$t_force_enable = $t_login_method == LOGIN_METHOD_COOKIE;
		return $next( $request->withAttribute( ATTRIBUTE_FORCE_API_ENABLED, $t_force_enable ), $response )->
			withHeader( HEADER_USERNAME, $t_username )->
			withHeader( HEADER_LOGIN_METHOD, $t_login_method );
	}
}
