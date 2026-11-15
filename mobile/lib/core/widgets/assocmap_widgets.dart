import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';

class AssocMapMark extends StatelessWidget {
  const AssocMapMark({super.key, this.size = 40});
  final double size;
  @override
  Widget build(BuildContext context) => Semantics(
    label: 'AssocMap symbol',
    image: true,
    child: ClipOval(
      child: Image.asset(
        'assets/brand/assocmap-symbol.png',
        width: size,
        height: size,
        fit: BoxFit.contain,
        excludeFromSemantics: true,
      ),
    ),
  );
}

class StatusBadge extends StatelessWidget {
  const StatusBadge({super.key, required this.status, this.label});
  final String status;
  final String? label;
  @override
  Widget build(BuildContext context) {
    final color = switch (status.toLowerCase()) {
      'pending' || 'planned' || 'not_started' => const Color(0xFF80540D),
      'rejected' || 'cancelled' => const Color(0xFF9C3333),
      'approved' || 'completed' || 'active' || 'ongoing' => AppTheme.green,
      _ => AppTheme.muted,
    };
    return Align(
      alignment: Alignment.centerLeft,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: color.withValues(alpha: .08),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          label ?? status.replaceAll('_', ' '),
          style: TextStyle(
            color: color,
            fontSize: 12,
            fontWeight: FontWeight.w600,
          ),
        ),
      ),
    );
  }
}

class DetailRow extends StatelessWidget {
  const DetailRow({super.key, required this.label, required this.value});
  final String label, value;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.symmetric(vertical: 8),
    child: LayoutBuilder(
      builder: (context, box) {
        final stacked =
            box.maxWidth < 300 ||
            MediaQuery.textScalerOf(context).scale(14) > 21;
        final labelWidget = Text(
          label,
          style: Theme.of(context).textTheme.bodySmall,
        );
        final valueWidget = Text(
          value,
          style: const TextStyle(fontWeight: FontWeight.w500),
        );
        return stacked
            ? Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [labelWidget, const SizedBox(height: 4), valueWidget],
              )
            : Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(width: box.maxWidth * .38, child: labelWidget),
                  const SizedBox(width: 12),
                  Expanded(child: valueWidget),
                ],
              );
      },
    ),
  );
}

String displayDate(BuildContext context, DateTime value) =>
    '${MaterialLocalizations.of(context).formatMediumDate(value)}, ${value.year}';

class AssocMapBrand extends StatelessWidget {
  const AssocMapBrand({super.key});
  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, box) => Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        const AssocMapMark(size: 36),
        if (box.maxWidth >= 170) ...[
          const SizedBox(width: 9),
          const Flexible(
            child: Text(
              'AssocMap',
              style: TextStyle(fontWeight: FontWeight.w700, fontSize: 20),
            ),
          ),
        ],
      ],
    ),
  );
}

class InformationCard extends StatelessWidget {
  const InformationCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
  });
  final Widget child;
  final EdgeInsets padding;
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(padding: padding, child: child),
  );
}

class ReadOnlyNotice extends StatelessWidget {
  const ReadOnlyNotice({
    super.key,
    required this.message,
    this.icon = Icons.lock_outline,
  });
  final String message;
  final IconData icon;
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: AppTheme.tint,
      borderRadius: BorderRadius.circular(12),
      border: Border.all(color: AppTheme.line),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 19, color: AppTheme.green),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            message,
            style: const TextStyle(
              color: AppTheme.green,
              fontSize: 13,
              height: 1.45,
            ),
          ),
        ),
      ],
    ),
  );
}

class PhotoPlaceholder extends StatelessWidget {
  const PhotoPlaceholder({
    super.key,
    this.height = 120,
    this.label = 'No photo available',
  });
  final double height;
  final String label;
  @override
  Widget build(BuildContext context) => Semantics(
    label: label,
    image: true,
    child: Container(
      constraints: BoxConstraints(minHeight: height),
      padding: const EdgeInsets.all(12),
      width: double.infinity,
      decoration: BoxDecoration(
        color: const Color(0xFFE2EBF6),
        borderRadius: BorderRadius.circular(12),
      ),
      child: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.image_outlined, color: AppTheme.muted, size: 30),
            if (height >= 100) ...[
              const SizedBox(height: 8),
              Text(label, style: Theme.of(context).textTheme.bodySmall),
            ],
          ],
        ),
      ),
    ),
  );
}

class PageContent extends StatelessWidget {
  const PageContent({super.key, required this.children});
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => SingleChildScrollView(
    physics: const AlwaysScrollableScrollPhysics(),
    keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
    padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
    child: Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: children,
        ),
      ),
    ),
  );
}

class SectionHeading extends StatelessWidget {
  const SectionHeading(this.title, {super.key});
  final String title;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(top: 20, bottom: 12),
    child: Text(
      title,
      style: Theme.of(
        context,
      ).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700),
    ),
  );
}

class MetricStrip extends StatelessWidget {
  const MetricStrip({super.key, required this.values, this.inverse = false});
  final Map<String, int?> values;
  final bool inverse;
  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, box) {
      final columns =
          MediaQuery.textScalerOf(context).scale(12) > 17 || box.maxWidth < 260
          ? 2
          : values.length;
      return Wrap(
        runSpacing: 16,
        children: [
          for (final entry in values.entries)
            SizedBox(
              width: box.maxWidth / columns,
              child: Column(
                children: [
                  Text(
                    entry.value?.toString() ?? '\u2014',
                    style: TextStyle(
                      fontSize: 24,
                      fontWeight: FontWeight.w700,
                      color: inverse ? Colors.white : AppTheme.green,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    entry.key,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 12,
                      color: inverse ? Colors.white : AppTheme.muted,
                    ),
                  ),
                ],
              ),
            ),
        ],
      );
    },
  );
}

class WorkspaceNavigation extends StatelessWidget {
  const WorkspaceNavigation({
    super.key,
    required this.selected,
    required this.onSelected,
  });
  final int selected;
  final ValueChanged<int> onSelected;
  static const labels = ['Home', 'Members', 'Programs', 'Register', 'Profile'];
  static const icons = [
    Icons.home_outlined,
    Icons.groups_outlined,
    Icons.map_outlined,
    Icons.person_add_alt,
    Icons.account_circle_outlined,
  ];
  @override
  Widget build(BuildContext context) => DecoratedBox(
    decoration: const BoxDecoration(
      color: Colors.white,
      border: Border(top: BorderSide(color: AppTheme.line)),
    ),
    child: NavigationBar(
      selectedIndex: selected,
      onDestinationSelected: onSelected,
      destinations: [
        for (var i = 0; i < labels.length; i++)
          NavigationDestination(
            key: ValueKey('workspace-tab-$i'),
            icon: Icon(icons[i]),
            label: labels[i],
            tooltip: labels[i],
          ),
      ],
    ),
  );
}
