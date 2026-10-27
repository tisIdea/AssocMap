import 'package:flutter/material.dart';
import '../../app/theme/app_theme.dart';

class AssocMapMark extends StatelessWidget {
  const AssocMapMark({super.key, this.size = 40});
  final double size;
  @override
  Widget build(BuildContext context) => Semantics(
    label: 'AssocMap symbol',
    image: true,
    child: Container(
      width: size,
      height: size,
      decoration: const BoxDecoration(
        shape: BoxShape.circle,
        color: Color(0xFFE8F1FC),
      ),
      child: Icon(
        Icons.location_on_outlined,
        size: size * .65,
        color: AppTheme.blue,
      ),
    ),
  );
}

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
    this.padding = const EdgeInsets.all(18),
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
      color: const Color(0xFFEAF2FC),
      borderRadius: BorderRadius.circular(12),
      border: Border.all(color: AppTheme.line),
    ),
    child: Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Icon(icon, size: 19, color: AppTheme.blue),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            message,
            style: const TextStyle(
              color: AppTheme.blue,
              fontSize: 12,
              height: 1.5,
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
    padding: const EdgeInsets.all(16),
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
                      fontSize: 25,
                      fontWeight: FontWeight.w700,
                      color: inverse ? Colors.white : AppTheme.blue,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    entry.key,
                    textAlign: TextAlign.center,
                    style: TextStyle(
                      fontSize: 11,
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
  Widget build(BuildContext context) => Material(
    color: Colors.white,
    child: SafeArea(
      top: false,
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (var i = 0; i < labels.length; i++)
              Expanded(
                child: Semantics(
                  selected: selected == i,
                  button: true,
                  child: InkWell(
                    key: ValueKey('workspace-tab-$i'),
                    onTap: () => onSelected(i),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(
                        vertical: 10,
                        horizontal: 2,
                      ),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 3,
                            ),
                            decoration: BoxDecoration(
                              color: selected == i
                                  ? const Color(0xFFE8F1FC)
                                  : Colors.transparent,
                              borderRadius: BorderRadius.circular(18),
                            ),
                            child: Icon(
                              icons[i],
                              color: selected == i
                                  ? AppTheme.blue
                                  : AppTheme.muted,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            labels[i],
                            textAlign: TextAlign.center,
                            style: TextStyle(
                              fontSize: 10,
                              fontWeight: selected == i
                                  ? FontWeight.w700
                                  : FontWeight.w400,
                              color: selected == i
                                  ? AppTheme.blue
                                  : AppTheme.muted,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ),
          ],
        ),
      ),
    ),
  );
}
