import 'package:material_ui/material_ui.dart';

import '../datos/api.dart';
import '../datos/repositorio.dart';
import '../estilo.dart';

class PantallaLogin extends StatefulWidget {
  const PantallaLogin({super.key, required this.repo});
  final Repositorio repo;

  @override
  State<PantallaLogin> createState() => _PantallaLoginState();
}

class _PantallaLoginState extends State<PantallaLogin> {
  final _form = GlobalKey<FormState>();
  final _url = TextEditingController();
  final _correo = TextEditingController();
  final _clave = TextEditingController();
  bool _ocupado = false;
  bool _verClave = false;
  bool _verServidor = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _precargar();
  }

  Future<void> _precargar() async {
    final url = await widget.repo.urlServidor();
    final correo = await widget.repo.ultimoCorreo();
    if (!mounted) return;
    setState(() {
      _url.text = url;
      _correo.text = correo ?? '';
      _verServidor = url.isEmpty;
    });
  }

  @override
  void dispose() {
    _url.dispose();
    _correo.dispose();
    _clave.dispose();
    super.dispose();
  }

  Future<void> _entrar() async {
    if (_ocupado || !_form.currentState!.validate()) return;
    setState(() {
      _ocupado = true;
      _error = null;
    });
    final mensajero = ScaffoldMessenger.of(context);
    try {
      final r = await widget.repo.login(_url.text, _correo.text, _clave.text);
      if (r.sinConexion) {
        mensajero.showSnackBar(const SnackBar(
          content: Text('Sin conexión: trabajando con los datos guardados en el teléfono.'),
          duration: Duration(seconds: 5),
        ));
      }
      // OrdenesApp cambia a la pantalla de inicio al haber usuario.
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = e is ErrorApi ? e.mensaje : 'No se pudo iniciar sesión: $e';
          _ocupado = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _form,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Icon(Icons.water_drop, size: 64, color: colorPrimario),
                    const SizedBox(height: 8),
                    Text('Órdenes de Trabajo',
                        textAlign: TextAlign.center, style: tema.textTheme.headlineSmall),
                    const SizedBox(height: 32),
                    TextFormField(
                      controller: _correo,
                      decoration: const InputDecoration(
                          labelText: 'Correo', prefixIcon: Icon(Icons.person_outline)),
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      autocorrect: false,
                      autofillHints: const [AutofillHints.username],
                      validator: (v) => (v == null || v.trim().isEmpty) ? 'Ingrese su correo' : null,
                    ),
                    const SizedBox(height: 16),
                    TextFormField(
                      controller: _clave,
                      decoration: InputDecoration(
                        labelText: 'Contraseña',
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          tooltip: _verClave ? 'Ocultar' : 'Mostrar',
                          icon: Icon(_verClave ? Icons.visibility_off : Icons.visibility),
                          onPressed: () => setState(() => _verClave = !_verClave),
                        ),
                      ),
                      obscureText: !_verClave,
                      autofillHints: const [AutofillHints.password],
                      onFieldSubmitted: (_) => _entrar(),
                      validator: (v) => (v == null || v.isEmpty) ? 'Ingrese su contraseña' : null,
                    ),
                    if (_verServidor) ...[
                      const SizedBox(height: 16),
                      TextFormField(
                        controller: _url,
                        decoration: const InputDecoration(
                          labelText: 'Servidor',
                          hintText: 'https://ordenes.empresa.gob.ec',
                          prefixIcon: Icon(Icons.dns_outlined),
                        ),
                        keyboardType: TextInputType.url,
                        autocorrect: false,
                        validator: (v) => (v == null || v.trim().isEmpty)
                            ? 'Indique la dirección del servidor'
                            : null,
                      ),
                    ],
                    if (_error != null) ...[
                      const SizedBox(height: 16),
                      Text(_error!, style: TextStyle(color: tema.colorScheme.error)),
                    ],
                    const SizedBox(height: 24),
                    FilledButton(
                      onPressed: _ocupado ? null : _entrar,
                      style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)),
                      child: _ocupado
                          ? const SizedBox(
                              width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Text('Ingresar'),
                    ),
                    if (!_verServidor)
                      TextButton.icon(
                        onPressed: () => setState(() => _verServidor = true),
                        icon: const Icon(Icons.settings, size: 18),
                        label: const Text('Servidor'),
                      ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
