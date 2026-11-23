import 'package:material_ui/material_ui.dart';

import 'datos/api.dart';
import 'datos/base_datos.dart';
import 'datos/repositorio.dart';
import 'estilo.dart';
import 'pantallas/inicio.dart';
import 'pantallas/login.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final bd = await BaseDatos.abrir();
  runApp(OrdenesApp(repo: Repositorio(bd, Api())));
}

class OrdenesApp extends StatelessWidget {
  const OrdenesApp({super.key, required this.repo});
  final Repositorio repo;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Órdenes de Trabajo',
      debugShowCheckedModeBanner: false,
      // Calendario, textos de los diálogos, etc. en español
      locale: const Locale('es'),
      supportedLocales: const [Locale('es')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: colorPrimario),
        inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
      ),
      home: ListenableBuilder(
        listenable: repo,
        builder: (context, _) =>
            repo.usuario == null ? PantallaLogin(repo: repo) : PantallaInicio(repo: repo),
      ),
    );
  }
}
