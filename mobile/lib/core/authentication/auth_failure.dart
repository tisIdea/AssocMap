enum AuthFailureCode {
  invalidCredentials,
  wrongRole,
  invalidAssociationLink,
  accountUnavailable,
  connectionUnavailable,
  sessionExpired,
  integrationUnavailable,
  invalidConfiguration,
  demoDisabled,
  unexpectedResponse,
  rateLimited,
  storageUnavailable,
}

class AuthFailure implements Exception {
  const AuthFailure(this.code);
  final AuthFailureCode code;

  String get message => switch (code) {
    AuthFailureCode.invalidCredentials =>
      'Unable to sign in. Check your email and password and try again.',
    AuthFailureCode.wrongRole =>
      'This mobile application is intended for Association Accounts. Please use the AssocMap web application.',
    AuthFailureCode.invalidAssociationLink =>
      'Your account is not currently linked to an active association. Please contact the system administrator.',
    AuthFailureCode.accountUnavailable =>
      'This account is currently unavailable. Please contact the system administrator.',
    AuthFailureCode.connectionUnavailable =>
      'The authentication service is unavailable. Check your connection and try again.',
    AuthFailureCode.sessionExpired =>
      'Your session has expired. Please sign in again.',
    AuthFailureCode.integrationUnavailable =>
      'Mobile sign-in is currently unavailable. Use the AssocMap web application.',
    AuthFailureCode.invalidConfiguration =>
      'Mobile sign-in is currently unavailable. Please contact the system administrator.',
    AuthFailureCode.rateLimited =>
      'Too many requests. Please wait a minute before trying again.',
    AuthFailureCode.storageUnavailable =>
      'Secure session storage is unavailable. Please restart the app and try again.',
    AuthFailureCode.demoDisabled =>
      'Mobile sign-in is unavailable with this configuration. Please contact the system administrator.',
    AuthFailureCode.unexpectedResponse =>
      'Unable to verify mobile access. Please try again or contact the system administrator.',
  };
}
