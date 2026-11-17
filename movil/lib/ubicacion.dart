import 'dart:async';

import 'package:geolocator/geolocator.dart';

class ErrorUbicacion implements Exception {
  ErrorUbicacion(this.mensaje);
  final String mensaje;
  @override
  String toString() => mensaje;
}

/// Posición actual del teléfono por GPS (funciona sin Internet).
Future<({double lat, double lng, double precision})> ubicacionActual() async {
  if (!await Geolocator.isLocationServiceEnabled()) {
    throw ErrorUbicacion('Active la ubicación (GPS) del teléfono.');
  }
  var permiso = await Geolocator.checkPermission();
  if (permiso == LocationPermission.denied) {
    permiso = await Geolocator.requestPermission();
  }
  if (permiso == LocationPermission.denied || permiso == LocationPermission.deniedForever) {
    throw ErrorUbicacion('La app no tiene permiso de ubicación.');
  }
  try {
    final p = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 30),
      ),
    );
    return (lat: p.latitude, lng: p.longitude, precision: p.accuracy);
  } on TimeoutException {
    throw ErrorUbicacion('No se obtuvo señal GPS. Intente a cielo abierto.');
  } on LocationServiceDisabledException {
    throw ErrorUbicacion('Active la ubicación (GPS) del teléfono.');
  }
}
